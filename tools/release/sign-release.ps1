param(
    [Parameter(Mandatory, Position = 0)]
    [string]$Tag,
    [string]$PrivateKeyPath = (Join-Path (Join-Path (Join-Path $HOME '.aidipanel') 'release-signing') 'release-signing-key.pem'),
    [string]$Repository = 'rezzaidr/AidiPanel'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$module = Join-Path $PSScriptRoot 'ReleaseSigning.psm1'
$publicKey = Join-Path $root 'config\release-signing-public.pub'
$privateKeyFullPath = [IO.Path]::GetFullPath($PrivateKeyPath)
$expectedAssets = @(
    'install-aidipanel.sh',
    'aidipanel',
    'aidipanel-panel-app.tar.gz',
    'SHA256SUMS',
    'release-signing-public.pub'
)

Import-Module $module -Force

function Invoke-NativeCapture {
    param(
        [Parameter(Mandatory)][string]$Command,
        [Parameter(Mandatory)][string[]]$Arguments,
        [Parameter(Mandatory)][string]$FailureMessage
    )

    $stderrPath = Join-Path ([IO.Path]::GetTempPath()) ('.aidipanel-stderr-' + [guid]::NewGuid().ToString('N'))
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & $Command @Arguments 2> $stderrPath
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    try {
        if ($exitCode -ne 0) {
            $detail = if (Test-Path -LiteralPath $stderrPath) {
                [IO.File]::ReadAllText($stderrPath).Trim()
            } else { '' }
            if ($detail) { throw "$FailureMessage $detail" }
            throw $FailureMessage
        }
        return @($output | ForEach-Object { [string]$_ })
    }
    finally {
        if (Test-Path -LiteralPath $stderrPath) {
            Remove-Item -LiteralPath $stderrPath -Force -ErrorAction SilentlyContinue
        }
    }
}

function Invoke-InteractiveNative {
    param(
        [Parameter(Mandatory)][string]$Command,
        [Parameter(Mandatory)][string[]]$Arguments,
        [Parameter(Mandatory)][string]$FailureMessage
    )

    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & $Command @Arguments
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) { throw $FailureMessage }
}

function Test-PathInsideRepository {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$RepositoryRoot
    )

    $fullPath = [IO.Path]::GetFullPath($Path)
    $fullRoot = [IO.Path]::GetFullPath($RepositoryRoot).TrimEnd([char[]]@('\', '/'))
    $rootPrefix = $fullRoot + [IO.Path]::DirectorySeparatorChar
    return $fullPath.Equals($fullRoot, [StringComparison]::OrdinalIgnoreCase) -or
        $fullPath.StartsWith($rootPrefix, [StringComparison]::OrdinalIgnoreCase)
}

function Get-DraftRelease {
    $json = Invoke-NativeCapture -Command $gh -Arguments @(
        'release', 'view', $Tag, '-R', $Repository,
        '--json', 'isDraft,url,tagName,assets'
    ) -FailureMessage "Draft release was not found for $Tag."
    try {
        $release = ($json -join [Environment]::NewLine) | ConvertFrom-Json
    }
    catch {
        throw "GitHub returned invalid release metadata for ${Tag}: $($_.Exception.Message)"
    }
    if ($release.tagName -ne $Tag) { throw "GitHub release tag mismatch: $($release.tagName)" }
    if ($release.isDraft -ne $true) { throw "Release $Tag is not an unpublished draft." }
    return $release
}

function Assert-ReleaseAssetSet {
    param(
        [Parameter(Mandatory)][object]$Release,
        [switch]$RequireSignature
    )

    $names = @($Release.assets | ForEach-Object { [string]$_.name } | Sort-Object)
    if (@($names | Group-Object | Where-Object Count -ne 1).Count -ne 0) {
        throw 'Draft release contains duplicate asset names.'
    }
    $allowed = @($expectedAssets)
    if ($RequireSignature) { $allowed += 'SHA256SUMS.sig' }
    elseif ('SHA256SUMS.sig' -in $names) { $allowed += 'SHA256SUMS.sig' }
    $allowed = @($allowed | Sort-Object)
    if (@(Compare-Object -ReferenceObject $allowed -DifferenceObject $names).Count -ne 0) {
        throw "Draft release asset set is invalid. Found: $($names -join ', ')"
    }
    return ('SHA256SUMS.sig' -in $names)
}

function Download-ReleaseAssets {
    param(
        [Parameter(Mandatory)][string]$Destination,
        [switch]$IncludeSignature
    )

    [IO.Directory]::CreateDirectory($Destination) | Out-Null
    $arguments = [System.Collections.Generic.List[string]]::new()
    foreach ($argument in @('release', 'download', $Tag, '-R', $Repository, '--dir', $Destination)) {
        $arguments.Add($argument)
    }
    foreach ($asset in $expectedAssets) {
        $arguments.Add('--pattern')
        $arguments.Add($asset)
    }
    if ($IncludeSignature) {
        $arguments.Add('--pattern')
        $arguments.Add('SHA256SUMS.sig')
    }
    Invoke-NativeCapture -Command $gh -Arguments $arguments.ToArray() `
        -FailureMessage "Could not download draft release assets for $Tag." | Out-Null
}

function Test-DownloadedRelease {
    param(
        [Parameter(Mandatory)][string]$Directory,
        [switch]$RequireSignature
    )

    $manifestPath = Join-Path $Directory 'SHA256SUMS'
    $manifestInfo = Read-ReleaseManifest -Path $manifestPath -ExpectedVersion $version
    Test-ManifestAssets -Manifest $manifestInfo -AssetDirectory $Directory
    Compare-ReleaseArtifactsToTag -RepositoryRoot $root -Tag $Tag -AssetDirectory $Directory

    $downloadedPublicKey = Join-Path $Directory 'release-signing-public.pub'
    if (-not (Test-Path -LiteralPath $downloadedPublicKey -PathType Leaf)) {
        throw 'Draft release is missing the public verification key.'
    }
    $canonicalBytes = [IO.File]::ReadAllBytes($publicKey)
    $downloadedBytes = [IO.File]::ReadAllBytes($downloadedPublicKey)
    if (@(Compare-Object -ReferenceObject $canonicalBytes -DifferenceObject $downloadedBytes).Count -ne 0) {
        throw 'Draft release public key differs from the canonical repository key.'
    }

    if ($RequireSignature) {
        Test-DetachedSignature -OpenSsl $openssl -Manifest $manifestPath `
            -Signature (Join-Path $Directory 'SHA256SUMS.sig') -PublicKey $publicKey
    }
    return $manifestPath
}

if ($Tag -notmatch '^v') { throw 'Release tag must start with v.' }
$version = $Tag.Substring(1)
Assert-ReleaseVersion -Version $version

if (-not (Test-Path -LiteralPath $privateKeyFullPath -PathType Leaf)) {
    throw "Encrypted private signing key was not found: $privateKeyFullPath"
}
if (Test-PathInsideRepository -Path $privateKeyFullPath -RepositoryRoot $root) {
    throw 'The private signing key must remain outside the repository.'
}
if (-not (Test-Path -LiteralPath $publicKey -PathType Leaf)) {
    throw "Canonical release public key was not found: $publicKey"
}

$openssl = Find-OpenSsl
$git = (Get-Command git -ErrorAction Stop).Source
$gh = (Get-Command gh -ErrorAction Stop).Source
Invoke-NativeCapture -Command $openssl -Arguments @('pkey', '-pubin', '-in', $publicKey, '-noout') `
    -FailureMessage 'Canonical release public key validation failed.' | Out-Null

$worktreeState = @(Invoke-NativeCapture -Command $git -Arguments @(
    '-C', $root, 'status', '--porcelain=v1', '--untracked-files=all'
) -FailureMessage 'Could not inspect the Git worktree.')
if ($worktreeState.Count -ne 0) {
    throw 'The release worktree must be completely clean, including untracked files.'
}

Invoke-NativeCapture -Command $gh -Arguments @('auth', 'status') `
    -FailureMessage 'GitHub CLI authentication is required.' | Out-Null

$localCommitOutput = @(Invoke-NativeCapture -Command $git -Arguments @(
    '-C', $root, 'rev-parse', "$Tag^{commit}"
) -FailureMessage "Local tag was not found: $Tag")
if ($localCommitOutput.Count -ne 1) { throw "Local tag metadata is ambiguous: $Tag" }
$localCommit = $localCommitOutput[0].Trim()
$remoteTagLines = @(Invoke-NativeCapture -Command $git -Arguments @(
    '-C', $root, 'ls-remote', '--tags', 'origin', "refs/tags/$Tag", "refs/tags/$Tag^{}"
) -FailureMessage "Could not read the remote tag: $Tag")
if ($remoteTagLines.Count -eq 0) { throw "Remote tag was not found: $Tag" }
$peeledLine = @($remoteTagLines | Where-Object { $_ -match '\^\{\}$' })
$remoteLine = if ($peeledLine.Count -eq 1) { $peeledLine[0] } else {
    $exactLine = @($remoteTagLines | Where-Object { $_ -match "refs/tags/$([regex]::Escape($Tag))$" })
    if ($exactLine.Count -ne 1) { throw "Remote tag metadata is ambiguous: $Tag" }
    $exactLine[0]
}
$remoteCommit = ($remoteLine -split '\s+')[0]
if ($localCommit -ne $remoteCommit) {
    throw "Local and remote tag commits differ for $Tag."
}

$release = Get-DraftRelease
$signatureExists = Assert-ReleaseAssetSet -Release $release
$temp = Join-Path ([IO.Path]::GetTempPath()) ('aidipanel-release-' + [guid]::NewGuid().ToString('N'))
$verification = Join-Path ([IO.Path]::GetTempPath()) ('aidipanel-release-verify-' + [guid]::NewGuid().ToString('N'))

try {
    Download-ReleaseAssets -Destination $temp -IncludeSignature:$signatureExists
    $manifestPath = Test-DownloadedRelease -Directory $temp -RequireSignature:$signatureExists
    $signaturePath = Join-Path $temp 'SHA256SUMS.sig'

    if (-not $signatureExists) {
        Write-Output "Signing $Tag. OpenSSL will request the private-key passphrase."
        Invoke-InteractiveNative -Command $openssl -Arguments @(
            'dgst', '-sha256', '-sign', $privateKeyFullPath,
            '-out', $signaturePath, $manifestPath
        ) -FailureMessage 'Release signing failed; the release remains draft.'
        Test-DetachedSignature -OpenSsl $openssl -Manifest $manifestPath `
            -Signature $signaturePath -PublicKey $publicKey
        Invoke-NativeCapture -Command $gh -Arguments @(
            'release', 'upload', $Tag, $signaturePath, '-R', $Repository
        ) -FailureMessage 'Signature upload failed; the release remains draft.' | Out-Null
    }

    $release = Get-DraftRelease
    Assert-ReleaseAssetSet -Release $release -RequireSignature | Out-Null
    Download-ReleaseAssets -Destination $verification -IncludeSignature
    Test-DownloadedRelease -Directory $verification -RequireSignature | Out-Null

    Invoke-NativeCapture -Command $gh -Arguments @(
        'release', 'edit', $Tag, '--draft=false', '-R', $Repository
    ) -FailureMessage 'Signature is valid but release publication failed; the release should remain draft.' | Out-Null

    $publishedJson = Invoke-NativeCapture -Command $gh -Arguments @(
        'release', 'view', $Tag, '-R', $Repository, '--json', 'isDraft,url,tagName'
    ) -FailureMessage 'Could not confirm the published release state.'
    $published = ($publishedJson -join [Environment]::NewLine) | ConvertFrom-Json
    if ($published.isDraft -ne $false -or $published.tagName -ne $Tag) {
        throw 'GitHub did not confirm the release as published.'
    }

    $fingerprint = (Get-FileHash -Algorithm SHA256 -LiteralPath $publicKey).Hash.ToLowerInvariant()
    Write-Output "Tag: $Tag"
    Write-Output "Commit: $localCommit"
    Write-Output "Public key SHA-256: $fingerprint"
    Write-Output 'Detached signature: verified from GitHub'
    Write-Output "Release: $($published.url)"
}
finally {
    foreach ($directory in @($temp, $verification)) {
        if (Test-Path -LiteralPath $directory) {
            Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}
