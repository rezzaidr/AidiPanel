$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw "Assertion failed: $Message" }
}

function Assert-Throws {
    param([scriptblock]$Action, [string]$Message)
    $threw = $false
    try { & $Action } catch { $threw = $true }
    if (-not $threw) { throw "Expected rejection: $Message" }
}

function Invoke-Checked {
    param([string]$Command, [string[]]$Arguments)
    & $Command @Arguments | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed ($LASTEXITCODE): $Command $($Arguments -join ' ')"
    }
}

function Invoke-SignerOrchestrationTests {
    param(
        [Parameter(Mandatory)][string]$TempRoot,
        [Parameter(Mandatory)][string]$Git,
        [Parameter(Mandatory)][string]$OpenSsl,
        [Parameter(Mandatory)][string]$PrivateKey,
        [Parameter(Mandatory)][string]$PublicKey
    )

    $projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    $fixtureRoot = Join-Path $TempRoot 'orchestration'
    $repo = Join-Path $fixtureRoot 'repo'
    $remote = Join-Path $fixtureRoot 'remote.git'
    $assets = Join-Path $fixtureRoot 'remote-assets'
    $mockBin = Join-Path $fixtureRoot 'mock-bin'
    $mockLog = Join-Path $fixtureRoot 'gh.log'
    $mockState = Join-Path $fixtureRoot 'draft.state'
    $panel = Join-Path $repo 'panel-app'
    $panelPublic = Join-Path $panel 'public'
    $releaseTools = Join-Path $repo 'tools\release'
    $repoConfig = Join-Path $repo 'config'
    foreach ($directory in @($panelPublic, $releaseTools, $repoConfig, $assets, $mockBin)) {
        [IO.Directory]::CreateDirectory($directory) | Out-Null
    }

    $utf8 = New-Object Text.UTF8Encoding($false)
    [IO.File]::WriteAllText((Join-Path $repo 'install.sh'), "#!/usr/bin/env bash`necho install`n", $utf8)
    [IO.File]::WriteAllText((Join-Path $repo 'aidipanel'), "#!/usr/bin/env bash`necho cli`n", $utf8)
    [IO.File]::WriteAllText((Join-Path $panel 'deploy-panel.sh'), "#!/usr/bin/env bash`necho deploy`n", $utf8)
    [IO.File]::WriteAllText((Join-Path $panelPublic 'index.php'), "<?php echo 'panel';`n", $utf8)
    Copy-Item -LiteralPath (Join-Path $projectRoot 'tools\release\ReleaseSigning.psm1') -Destination $releaseTools
    Copy-Item -LiteralPath (Join-Path $projectRoot 'tools\release\sign-release.ps1') -Destination $releaseTools
    Copy-Item -LiteralPath $PublicKey -Destination (Join-Path $repoConfig 'release-signing-public.pub')

    Invoke-Checked $Git @('-C', $repo, 'init', '-q')
    Invoke-Checked $Git @('-C', $repo, 'config', 'core.autocrlf', 'false')
    Invoke-Checked $Git @('-C', $repo, 'config', 'user.email', 'release-test@aidipanel.invalid')
    Invoke-Checked $Git @('-C', $repo, 'config', 'user.name', 'AidiPanel Release Test')
    Invoke-Checked $Git @('-C', $repo, 'add', '--all')
    Invoke-Checked $Git @('-C', $repo, 'commit', '-q', '-m', 'fixture')
    Invoke-Checked $Git @('-C', $repo, 'tag', 'v1.3.3')
    Invoke-Checked $Git @('init', '--bare', '-q', $remote)
    Invoke-Checked $Git @('-C', $repo, 'remote', 'add', 'origin', $remote)
    Invoke-Checked $Git @('-C', $repo, 'push', '-q', 'origin', 'HEAD:refs/heads/master')
    Invoke-Checked $Git @('-C', $repo, 'push', '-q', 'origin', 'v1.3.3')

    Copy-Item -LiteralPath (Join-Path $repo 'install.sh') -Destination (Join-Path $assets 'install-aidipanel.sh')
    Copy-Item -LiteralPath (Join-Path $repo 'aidipanel') -Destination (Join-Path $assets 'aidipanel')
    Invoke-Checked $Git @(
        '-C', $repo, 'archive', '--format=tar.gz',
        "--output=$(Join-Path $assets 'aidipanel-panel-app.tar.gz')", 'v1.3.3', '--', 'panel-app'
    )
    Copy-Item -LiteralPath $PublicKey -Destination (Join-Path $assets 'release-signing-public.pub')
    $manifestLines = [System.Collections.Generic.List[string]]::new()
    $manifestLines.Add('# AIDIPANEL_RELEASE_VERSION=1.3.3')
    foreach ($name in @('install-aidipanel.sh', 'aidipanel', 'aidipanel-panel-app.tar.gz')) {
        $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $assets $name)).Hash.ToLowerInvariant()
        $manifestLines.Add("$hash  $name")
    }
    [IO.File]::WriteAllLines((Join-Path $assets 'SHA256SUMS'), $manifestLines, $utf8)

    $mockScript = @'
param([Parameter(ValueFromRemainingArguments = $true)][string[]]$CommandArgs)
$ErrorActionPreference = 'Stop'
[IO.File]::AppendAllText($env:GH_MOCK_LOG, (($CommandArgs | ConvertTo-Json -Compress) + [Environment]::NewLine))

function Option-Value([string]$Name) {
    for ($index = 0; $index -lt $CommandArgs.Count - 1; $index++) {
        if ($CommandArgs[$index] -eq $Name) { return $CommandArgs[$index + 1] }
    }
    return $null
}

if ($CommandArgs.Count -ge 2 -and $CommandArgs[0] -eq 'auth' -and $CommandArgs[1] -eq 'status') {
    if ($env:GH_MOCK_MODE -eq 'auth_fail') { exit 1 }
    exit 0
}

if ($CommandArgs.Count -lt 3 -or $CommandArgs[0] -ne 'release') { exit 2 }
$action = $CommandArgs[1]
$tag = $CommandArgs[2]
switch ($action) {
    'view' {
        if ($env:GH_MOCK_MODE -eq 'missing_release') { exit 1 }
        $draft = ([IO.File]::ReadAllText($env:GH_MOCK_STATE).Trim() -eq 'true')
        if ($env:GH_MOCK_MODE -eq 'non_draft') { $draft = $false }
        $releaseAssets = @(
            Get-ChildItem -LiteralPath $env:GH_MOCK_ASSETS -File |
                Sort-Object Name |
                ForEach-Object { [pscustomobject]@{ name = $_.Name } }
        )
        [pscustomobject]@{
            isDraft = $draft
            url = "https://example.invalid/releases/tag/$tag"
            tagName = $tag
            assets = $releaseAssets
        } | ConvertTo-Json -Depth 4 -Compress
        exit 0
    }
    'download' {
        $destination = Option-Value '--dir'
        if (-not $destination) { exit 2 }
        [IO.Directory]::CreateDirectory($destination) | Out-Null
        for ($index = 0; $index -lt $CommandArgs.Count - 1; $index++) {
            if ($CommandArgs[$index] -ne '--pattern') { continue }
            $name = $CommandArgs[$index + 1]
            $source = Join-Path $env:GH_MOCK_ASSETS $name
            if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { exit 1 }
            Copy-Item -LiteralPath $source -Destination (Join-Path $destination $name)
        }
        exit 0
    }
    'upload' {
        if ($env:GH_MOCK_MODE -eq 'upload_fail') { exit 1 }
        $source = $CommandArgs[3]
        $destination = Join-Path $env:GH_MOCK_ASSETS ([IO.Path]::GetFileName($source))
        if (Test-Path -LiteralPath $destination) { exit 1 }
        Copy-Item -LiteralPath $source -Destination $destination
        exit 0
    }
    'edit' {
        if ($env:GH_MOCK_MODE -eq 'edit_fail') { exit 1 }
        [IO.File]::WriteAllText($env:GH_MOCK_STATE, 'false')
        exit 0
    }
    default { exit 2 }
}
'@
    [IO.File]::WriteAllText((Join-Path $mockBin 'gh-mock.ps1'), $mockScript, $utf8)
    [IO.File]::WriteAllText(
        (Join-Path $mockBin 'gh.cmd'),
        "@echo off`r`npowershell.exe -NoProfile -ExecutionPolicy Bypass -File `"%~dp0gh-mock.ps1`" %*`r`nexit /b %ERRORLEVEL%`r`n",
        $utf8
    )

    $signer = Join-Path $releaseTools 'sign-release.ps1'
    $originalPath = $env:PATH
    $originalMockLog = $env:GH_MOCK_LOG
    $originalMockState = $env:GH_MOCK_STATE
    $originalMockAssets = $env:GH_MOCK_ASSETS
    $originalMockMode = $env:GH_MOCK_MODE
    $env:PATH = "$mockBin;$originalPath"
    $env:GH_MOCK_LOG = $mockLog
    $env:GH_MOCK_STATE = $mockState
    $env:GH_MOCK_ASSETS = $assets

    function Reset-Mock([string]$Mode = 'normal', [bool]$Draft = $true) {
        $env:GH_MOCK_MODE = $Mode
        [IO.File]::WriteAllText($mockLog, '')
        [IO.File]::WriteAllText($mockState, $Draft.ToString().ToLowerInvariant())
    }
    function Invoke-Signer([string]$ReleaseTag, [string]$KeyPath) {
        $previousErrorActionPreference = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $signer $ReleaseTag `
                -PrivateKeyPath $KeyPath -Repository 'mock/aidipanel' 2>&1
            $status = $LASTEXITCODE
        }
        finally {
            $ErrorActionPreference = $previousErrorActionPreference
        }
        return [pscustomobject]@{ Status = $status; Output = @($output) }
    }
    function Mock-Operations {
        if (-not (Test-Path -LiteralPath $mockLog)) { return @() }
        return @([IO.File]::ReadAllLines($mockLog))
    }
    function Assert-NoUploadOrEdit([string]$Label) {
        $operations = Mock-Operations
        Assert-True (-not [bool]($operations -match '"upload"')) "$Label does not upload"
        Assert-True (-not [bool]($operations -match '"edit"')) "$Label does not publish"
    }

    try {
        Reset-Mock
        $result = Invoke-Signer '1.3.3' $PrivateKey
        Assert-True ($result.Status -ne 0) 'invalid tag is rejected'
        Assert-True (@(Mock-Operations).Count -eq 0) 'invalid tag exits before GitHub access'

        [IO.File]::WriteAllText((Join-Path $repo 'dirty.txt'), 'dirty', $utf8)
        Reset-Mock
        $result = Invoke-Signer 'v1.3.3' $PrivateKey
        Assert-True ($result.Status -ne 0) 'dirty worktree is rejected'
        Assert-True (@(Mock-Operations).Count -eq 0) 'dirty worktree exits before GitHub access'
        Remove-Item -LiteralPath (Join-Path $repo 'dirty.txt') -Force

        Reset-Mock -Mode 'non_draft'
        $result = Invoke-Signer 'v1.3.3' $PrivateKey
        Assert-True ($result.Status -ne 0) 'published release state is rejected'
        Assert-NoUploadOrEdit 'published release rejection'

        $missingAsset = Join-Path $assets 'release-signing-public.pub'
        $missingBackup = Join-Path $fixtureRoot 'release-signing-public.pub.bak'
        Move-Item -LiteralPath $missingAsset -Destination $missingBackup
        Reset-Mock
        $result = Invoke-Signer 'v1.3.3' $PrivateKey
        Assert-True ($result.Status -ne 0) 'missing remote asset is rejected'
        Assert-NoUploadOrEdit 'missing asset rejection'
        Move-Item -LiteralPath $missingBackup -Destination $missingAsset

        $extraAsset = Join-Path $assets 'unexpected.bin'
        [IO.File]::WriteAllText($extraAsset, 'unexpected', $utf8)
        Reset-Mock
        $result = Invoke-Signer 'v1.3.3' $PrivateKey
        Assert-True ($result.Status -ne 0) 'extra remote asset is rejected'
        Assert-NoUploadOrEdit 'extra asset rejection'
        Remove-Item -LiteralPath $extraAsset -Force

        $badPrivateKey = Join-Path $fixtureRoot 'invalid-private.pem'
        [IO.File]::WriteAllText($badPrivateKey, 'not a private key', $utf8)
        Reset-Mock
        $result = Invoke-Signer 'v1.3.3' $badPrivateKey
        Assert-True ($result.Status -ne 0) 'signing failure is rejected'
        Assert-NoUploadOrEdit 'signing failure'

        Reset-Mock -Mode 'upload_fail'
        $result = Invoke-Signer 'v1.3.3' $PrivateKey
        Assert-True ($result.Status -ne 0) 'signature upload failure is rejected'
        $operations = Mock-Operations
        Assert-True ([bool]($operations -match '"upload"')) 'upload failure reaches upload'
        Assert-True (-not [bool]($operations -match '"edit"')) 'upload failure never publishes'

        $remoteManifest = Join-Path $assets 'SHA256SUMS'
        $remoteSignature = Join-Path $assets 'SHA256SUMS.sig'
        Invoke-Checked $OpenSsl @('dgst', '-sha256', '-sign', $PrivateKey, '-out', $remoteSignature, $remoteManifest)
        Reset-Mock
        $result = Invoke-Signer 'v1.3.3' $PrivateKey
        Assert-True ($result.Status -eq 0) 'valid existing signature resumes publication'
        $operations = Mock-Operations
        Assert-True (-not [bool]($operations -match '"upload"')) 'resume never replaces signature'
        Assert-True (@($operations -match '"edit"').Count -eq 1) 'resume publishes once'

        Remove-Item -LiteralPath $remoteSignature -Force
        Reset-Mock
        $result = Invoke-Signer 'v1.3.3' $PrivateKey
        Assert-True ($result.Status -eq 0) 'new signature flow succeeds'
        $operations = Mock-Operations
        $uploadIndexes = @(for ($index = 0; $index -lt $operations.Count; $index++) {
            if ($operations[$index] -match '"upload"') { $index }
        })
        $editIndexes = @(for ($index = 0; $index -lt $operations.Count; $index++) {
            if ($operations[$index] -match '"edit"') { $index }
        })
        Assert-True ($uploadIndexes.Count -eq 1) 'successful flow uploads once'
        Assert-True ($editIndexes.Count -eq 1) 'successful flow publishes once'
        Assert-True ($uploadIndexes[0] -lt $editIndexes[0]) 'signature upload precedes publication'
        Assert-True (([IO.File]::ReadAllText($mockState).Trim()) -eq 'false') 'successful flow leaves release published'
        Test-DetachedSignature -OpenSsl $OpenSsl -Manifest $remoteManifest `
            -Signature $remoteSignature -PublicKey $PublicKey
    }
    finally {
        $env:PATH = $originalPath
        $env:GH_MOCK_LOG = $originalMockLog
        $env:GH_MOCK_STATE = $originalMockState
        $env:GH_MOCK_ASSETS = $originalMockAssets
        $env:GH_MOCK_MODE = $originalMockMode
    }
}

$module = Join-Path $PSScriptRoot '..\..\tools\release\ReleaseSigning.psm1'
Import-Module $module -Force
$releaseSigningModule = Get-Module ReleaseSigning
$regularGit = & $releaseSigningModule { ConvertTo-ComparableTarMode '-rw-rw-r--' }
$regularCheckout = & $releaseSigningModule { ConvertTo-ComparableTarMode '-rw-r--r--' }
$executableGit = & $releaseSigningModule { ConvertTo-ComparableTarMode '-rwxrwxr-x' }
$executableCheckout = & $releaseSigningModule { ConvertTo-ComparableTarMode '-rwxr-xr-x' }
$groupExecutableDrift = & $releaseSigningModule { ConvertTo-ComparableTarMode '-rw-r-xr--' }
$worldWriteDrift = & $releaseSigningModule { ConvertTo-ComparableTarMode '-rw-rw-rw-' }

Assert-True ($regularGit -eq $regularCheckout) 'regular Git/archive umask difference is normalized'
Assert-True ($executableGit -eq $executableCheckout) 'executable Git/archive umask difference is normalized'
Assert-True ($regularCheckout -ne $executableCheckout) 'owner executable drift remains distinguishable'
Assert-True ($regularCheckout -ne $groupExecutableDrift) 'group executable drift remains distinguishable'
Assert-True ($regularCheckout -ne $worldWriteDrift) 'world-write drift remains distinguishable'

$openssl = Find-OpenSsl
$git = (Get-Command git -ErrorAction Stop).Source
$tar = (Get-Command tar -ErrorAction Stop).Source
$temp = Join-Path ([IO.Path]::GetTempPath()) ("aidipanel-release-signing-test-" + [guid]::NewGuid().ToString('N'))

try {
    $repo = Join-Path $temp 'repo'
    $assets = Join-Path $temp 'assets'
    $panel = Join-Path $repo 'panel-app'
    $panelPublic = Join-Path $panel 'public'
    [IO.Directory]::CreateDirectory($panelPublic) | Out-Null
    [IO.Directory]::CreateDirectory($assets) | Out-Null

    [IO.File]::WriteAllText((Join-Path $repo 'install.sh'), "#!/usr/bin/env bash`necho install`n", [Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText((Join-Path $repo 'aidipanel'), "#!/usr/bin/env bash`necho cli`n", [Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText((Join-Path $panel 'deploy-panel.sh'), "#!/usr/bin/env bash`necho deploy`n", [Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText((Join-Path $panelPublic 'index.php'), "<?php echo 'panel';`n", [Text.UTF8Encoding]::new($false))

    Invoke-Checked $git @('-C', $repo, 'init', '-q')
    Invoke-Checked $git @('-C', $repo, 'config', 'core.autocrlf', 'false')
    Invoke-Checked $git @('-C', $repo, 'config', 'user.email', 'release-test@aidipanel.invalid')
    Invoke-Checked $git @('-C', $repo, 'config', 'user.name', 'AidiPanel Release Test')
    Invoke-Checked $git @('-C', $repo, 'add', '--', 'install.sh', 'aidipanel', 'panel-app')
    Invoke-Checked $git @('-C', $repo, 'commit', '-q', '-m', 'fixture')
    Invoke-Checked $git @('-C', $repo, 'tag', 'v1.3.3')

    Copy-Item -LiteralPath (Join-Path $repo 'install.sh') -Destination (Join-Path $assets 'install-aidipanel.sh')
    Copy-Item -LiteralPath (Join-Path $repo 'aidipanel') -Destination (Join-Path $assets 'aidipanel')
    Invoke-Checked $git @(
        '-C', $repo, 'archive', '--format=tar.gz',
        "--output=$(Join-Path $assets 'aidipanel-panel-app.tar.gz')", 'v1.3.3', '--', 'panel-app'
    )

    $assetNames = @('install-aidipanel.sh', 'aidipanel', 'aidipanel-panel-app.tar.gz')
    $manifestLines = [System.Collections.Generic.List[string]]::new()
    $manifestLines.Add('# AIDIPANEL_RELEASE_VERSION=1.3.3')
    foreach ($name in $assetNames) {
        $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $assets $name)).Hash.ToLowerInvariant()
        $manifestLines.Add("$hash  $name")
    }
    $manifest = Join-Path $assets 'SHA256SUMS'
    [IO.File]::WriteAllLines($manifest, $manifestLines, [Text.UTF8Encoding]::new($false))

    $privateKey = Join-Path $temp 'fixture-private.pem'
    $publicKey = Join-Path $temp 'fixture-public.pub'
    $signature = Join-Path $assets 'SHA256SUMS.sig'
    Invoke-Checked $openssl @('genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:P-256', '-out', $privateKey)
    Invoke-Checked $openssl @('pkey', '-in', $privateKey, '-pubout', '-out', $publicKey)
    Invoke-Checked $openssl @('dgst', '-sha256', '-sign', $privateKey, '-out', $signature, $manifest)

    Assert-ReleaseVersion '1.3.3'
    Assert-ReleaseVersion '1.3.3-rc1'
    Assert-Throws { Assert-ReleaseVersion '1.3' } 'short version'
    Assert-Throws { Assert-ReleaseVersion '1.3.3-beta1' } 'unsupported prerelease'

    $manifestInfo = Read-ReleaseManifest -Path $manifest -ExpectedVersion '1.3.3'
    Assert-True ($manifestInfo.Checksums.Count -eq 3) 'manifest has exactly three checksums'
    Test-ManifestAssets -Manifest $manifestInfo -AssetDirectory $assets
    Test-DetachedSignature -OpenSsl $openssl -Manifest $manifest -Signature $signature -PublicKey $publicKey
    Compare-ReleaseArtifactsToTag -RepositoryRoot $repo -Tag 'v1.3.3' -AssetDirectory $assets

    Invoke-Checked $git @('-C', $repo, 'update-index', '--chmod=+x', 'panel-app/public/index.php')
    Invoke-Checked $git @('-C', $repo, 'commit', '-q', '-m', 'mode drift fixture')
    $panelArchive = Join-Path $assets 'aidipanel-panel-app.tar.gz'
    Invoke-Checked $git @(
        '-C', $repo, 'archive', '--format=tar.gz',
        "--output=$panelArchive", 'HEAD', '--', 'panel-app'
    )
    Assert-Throws {
        Compare-ReleaseArtifactsToTag -RepositoryRoot $repo -Tag 'v1.3.3' -AssetDirectory $assets
    } 'panel archive executable mode differs from exact tag'

    Invoke-Checked $git @(
        '-C', $repo, 'archive', '--format=tar.gz',
        "--output=$panelArchive", 'v1.3.3', '--', 'panel-app'
    )

    $originalManifest = [IO.File]::ReadAllBytes($manifest)
    [IO.File]::AppendAllText($manifest, "`n")
    Assert-Throws {
        Test-DetachedSignature -OpenSsl $openssl -Manifest $manifest -Signature $signature -PublicKey $publicKey
    } 'tampered manifest signature'
    [IO.File]::WriteAllBytes($manifest, $originalManifest)

    $wrongPrivate = Join-Path $temp 'wrong-private.pem'
    $wrongPublic = Join-Path $temp 'wrong-public.pub'
    Invoke-Checked $openssl @('genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:P-256', '-out', $wrongPrivate)
    Invoke-Checked $openssl @('pkey', '-in', $wrongPrivate, '-pubout', '-out', $wrongPublic)
    Assert-Throws {
        Test-DetachedSignature -OpenSsl $openssl -Manifest $manifest -Signature $signature -PublicKey $wrongPublic
    } 'wrong public key'

    $cliAsset = Join-Path $assets 'aidipanel'
    $originalCli = [IO.File]::ReadAllBytes($cliAsset)
    [IO.File]::AppendAllText($cliAsset, 'tampered')
    Assert-Throws { Test-ManifestAssets -Manifest $manifestInfo -AssetDirectory $assets } 'tampered asset checksum'
    Assert-Throws {
        Compare-ReleaseArtifactsToTag -RepositoryRoot $repo -Tag 'v1.3.3' -AssetDirectory $assets
    } 'asset differs from exact tag'
    [IO.File]::WriteAllBytes($cliAsset, $originalCli)

    $badManifest = Join-Path $temp 'bad-SHA256SUMS'
    [IO.File]::WriteAllLines($badManifest, @(
        '# AIDIPANEL_RELEASE_VERSION=1.3.3',
        $manifestLines[1],
        $manifestLines[1],
        $manifestLines[3]
    ), [Text.UTF8Encoding]::new($false))
    Assert-Throws { Read-ReleaseManifest -Path $badManifest -ExpectedVersion '1.3.3' } 'duplicate manifest entry'

    $extraFile = Join-Path $panel 'unexpected.txt'
    [IO.File]::WriteAllText($extraFile, 'not tracked', [Text.UTF8Encoding]::new($false))
    Invoke-Checked $tar @('-czf', (Join-Path $assets 'aidipanel-panel-app.tar.gz'), '-C', $repo, 'panel-app')
    Assert-Throws {
        Compare-ReleaseArtifactsToTag -RepositoryRoot $repo -Tag 'v1.3.3' -AssetDirectory $assets
    } 'panel archive contains an extra file'

    Invoke-SignerOrchestrationTests -TempRoot $temp -Git $git -OpenSsl $openssl `
        -PrivateKey $privateKey -PublicKey $publicKey

    Write-Output 'release signing integration test passed'
}
finally {
    if (Test-Path -LiteralPath $temp) {
        Remove-Item -LiteralPath $temp -Recurse -Force
    }
}
