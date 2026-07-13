Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Find-OpenSsl {
    $command = Get-Command openssl -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }

    if ($env:ProgramFiles) {
        $gitOpenSsl = Join-Path $env:ProgramFiles 'Git\usr\bin\openssl.exe'
        if (Test-Path -LiteralPath $gitOpenSsl -PathType Leaf) { return $gitOpenSsl }
    }

    throw 'OpenSSL was not found in PATH or Git for Windows.'
}

function Assert-ReleaseVersion {
    param([Parameter(Mandatory)][string]$Version)

    if ($Version -notmatch '^\d+\.\d+\.\d+(?:-rc\d+)?$') {
        throw "Invalid AidiPanel release version: $Version"
    }
}

function Read-ReleaseManifest {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$ExpectedVersion
    )

    Assert-ReleaseVersion -Version $ExpectedVersion
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Release manifest was not found: $Path"
    }

    $lines = [IO.File]::ReadAllLines($Path)
    if ($lines.Count -ne 4) {
        throw 'SHA256SUMS must contain one version header and three checksums.'
    }
    if ($lines[0] -ne "# AIDIPANEL_RELEASE_VERSION=$ExpectedVersion") {
        throw 'Signed release version does not match the requested tag.'
    }

    $expected = @('install-aidipanel.sh', 'aidipanel', 'aidipanel-panel-app.tar.gz')
    $checksums = [ordered]@{}
    foreach ($line in $lines[1..3]) {
        if ($line -notmatch '^([0-9a-f]{64})  ([A-Za-z0-9._-]+)$') {
            throw "Invalid checksum line: $line"
        }
        $name = $Matches[2]
        if ($name -notin $expected -or $checksums.Contains($name)) {
            throw "Unexpected or duplicate asset: $name"
        }
        $checksums[$name] = $Matches[1]
    }
    if ($checksums.Count -ne 3) {
        throw 'Manifest asset set is incomplete.'
    }

    [pscustomobject]@{
        Version   = $ExpectedVersion
        Checksums = $checksums
    }
}

function Test-ManifestAssets {
    param(
        [Parameter(Mandatory)][object]$Manifest,
        [Parameter(Mandatory)][string]$AssetDirectory
    )

    foreach ($entry in $Manifest.Checksums.GetEnumerator()) {
        $path = Join-Path $AssetDirectory $entry.Key
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            throw "Missing asset: $($entry.Key)"
        }
        $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
        if ($actual -ne $entry.Value) {
            throw "Checksum mismatch: $($entry.Key)"
        }
    }
}

function Test-DetachedSignature {
    param(
        [Parameter(Mandatory)][string]$OpenSsl,
        [Parameter(Mandatory)][string]$Manifest,
        [Parameter(Mandatory)][string]$Signature,
        [Parameter(Mandatory)][string]$PublicKey
    )

    foreach ($path in @($OpenSsl, $Manifest, $Signature, $PublicKey)) {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            throw "Signature verification input was not found: $path"
        }
    }

    & $OpenSsl dgst -sha256 -verify $PublicKey -signature $Signature $Manifest 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Detached release signature verification failed.'
    }
}

function Invoke-NativeChecked {
    param(
        [Parameter(Mandatory)][string]$Command,
        [Parameter(Mandatory)][string[]]$Arguments,
        [Parameter(Mandatory)][string]$FailureMessage
    )

    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & $Command @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($exitCode -ne 0) {
        $detail = ($output | Out-String).Trim()
        if ($detail) { throw "$FailureMessage $detail" }
        throw $FailureMessage
    }
    return @($output)
}

function Get-RelativeFileMap {
    param([Parameter(Mandatory)][string]$BasePath)

    $base = [IO.Path]::GetFullPath($BasePath).TrimEnd([char[]]@('\', '/'))
    $map = [ordered]@{}
    foreach ($file in Get-ChildItem -LiteralPath $base -Recurse -Force -File) {
        if (($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw "Archive extraction contains a reparse point: $($file.FullName)"
        }
        $relative = $file.FullName.Substring($base.Length).TrimStart([char[]]@('\', '/')).Replace('\', '/')
        $map[$relative] = (Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash.ToLowerInvariant()
    }
    return $map
}

function Get-TarListing {
    param(
        [Parameter(Mandatory)][string]$Tar,
        [Parameter(Mandatory)][string]$Archive,
        [switch]$Compressed,
        [switch]$LongFormat
    )

    $flag = if ($LongFormat) {
        if ($Compressed) { '-tvzf' } else { '-tvf' }
    } else {
        if ($Compressed) { '-tzf' } else { '-tf' }
    }
    $output = Invoke-NativeChecked -Command $Tar -Arguments @($flag, $Archive) -FailureMessage "Could not inspect archive: $Archive"
    return @($output | ForEach-Object { [string]$_ })
}

function Assert-SafePanelArchive {
    param(
        [Parameter(Mandatory)][string]$Tar,
        [Parameter(Mandatory)][string]$Archive
    )

    $entries = Get-TarListing -Tar $Tar -Archive $Archive -Compressed
    if ($entries.Count -eq 0) { throw 'Panel archive is empty.' }

    foreach ($rawEntry in $entries) {
        $entry = $rawEntry.TrimEnd('/')
        if (-not $entry) { continue }
        if ($entry -match '\s' -or $entry.StartsWith('/') -or $entry.StartsWith('\') -or
            $entry -match '^[A-Za-z]:' -or $entry -match '(^|[\\/])\.\.($|[\\/])' -or
            ($entry -ne 'panel-app' -and -not $entry.StartsWith('panel-app/'))) {
            throw "Unsafe panel archive path: $rawEntry"
        }
    }

    foreach ($line in Get-TarListing -Tar $Tar -Archive $Archive -Compressed -LongFormat) {
        if ($line -match '^[lh]') {
            throw "Panel archive links are not allowed: $line"
        }
    }
}

function Get-TarModeMap {
    param(
        [Parameter(Mandatory)][string]$Tar,
        [Parameter(Mandatory)][string]$Archive,
        [switch]$Compressed,
        [string]$Prefix = ''
    )

    $map = [ordered]@{}
    foreach ($line in Get-TarListing -Tar $Tar -Archive $Archive -Compressed:$Compressed -LongFormat) {
        if ($line.Length -lt 10) { throw "Unrecognized tar listing: $line" }
        $mode = $line.Substring(0, 10)
        if ($mode[0] -eq 'l' -or $mode[0] -eq 'h') { throw "Archive links are not allowed: $line" }
        if ($mode[0] -eq 'd') { continue }
        $parts = @($line -split '\s+')
        $path = $parts[-1].TrimEnd('/').Replace('\', '/')
        if ($Prefix -and -not $path.StartsWith($Prefix)) { continue }
        $map[$path] = $mode
    }
    return $map
}

function Compare-Map {
    param(
        [Parameter(Mandatory)][System.Collections.IDictionary]$Expected,
        [Parameter(Mandatory)][System.Collections.IDictionary]$Actual,
        [Parameter(Mandatory)][string]$Label
    )

    $expectedKeys = @($Expected.Keys | Sort-Object)
    $actualKeys = @($Actual.Keys | Sort-Object)
    if (@(Compare-Object -ReferenceObject $expectedKeys -DifferenceObject $actualKeys).Count -ne 0) {
        throw "$Label file set differs from the exact Git tag."
    }
    foreach ($key in $expectedKeys) {
        if ($Expected[$key] -ne $Actual[$key]) {
            throw "$Label differs from the exact Git tag: $key"
        }
    }
}

function Compare-ReleaseArtifactsToTag {
    param(
        [Parameter(Mandatory)][string]$RepositoryRoot,
        [Parameter(Mandatory)][string]$Tag,
        [Parameter(Mandatory)][string]$AssetDirectory
    )

    if (-not (Test-Path -LiteralPath $RepositoryRoot -PathType Container)) {
        throw "Repository root was not found: $RepositoryRoot"
    }
    Assert-ReleaseVersion -Version ($Tag -replace '^v', '')
    if ($Tag -notmatch '^v') { throw "Release tag must start with v: $Tag" }

    $git = (Get-Command git -ErrorAction Stop).Source
    $tar = (Get-Command tar -ErrorAction Stop).Source
    Invoke-NativeChecked -Command $git -Arguments @('-C', $RepositoryRoot, 'rev-parse', '--verify', "$Tag^{commit}") `
        -FailureMessage "Git tag was not found: $Tag" | Out-Null

    $temp = Join-Path ([IO.Path]::GetTempPath()) ("aidipanel-tag-compare-" + [guid]::NewGuid().ToString('N'))
    $sourceArchive = Join-Path $temp 'source.tar'
    $sourceDirectory = Join-Path $temp 'source'
    $releaseDirectory = Join-Path $temp 'release'
    [IO.Directory]::CreateDirectory($sourceDirectory) | Out-Null
    [IO.Directory]::CreateDirectory($releaseDirectory) | Out-Null

    try {
        Invoke-NativeChecked -Command $git -Arguments @(
            '-C', $RepositoryRoot, 'archive', '--format=tar', "--output=$sourceArchive", $Tag, '--',
            'install.sh', 'aidipanel', 'panel-app'
        ) -FailureMessage 'Could not create an archive from the exact Git tag.' | Out-Null
        Invoke-NativeChecked -Command $tar -Arguments @('-xf', $sourceArchive, '-C', $sourceDirectory) `
            -FailureMessage 'Could not extract the exact Git tag archive.' | Out-Null

        $releasePanelArchive = Join-Path $AssetDirectory 'aidipanel-panel-app.tar.gz'
        Assert-SafePanelArchive -Tar $tar -Archive $releasePanelArchive
        Invoke-NativeChecked -Command $tar -Arguments @('-xzf', $releasePanelArchive, '-C', $releaseDirectory) `
            -FailureMessage 'Could not extract the release panel archive.' | Out-Null

        $directFiles = @(
            @{ Source = (Join-Path $sourceDirectory 'install.sh'); Asset = (Join-Path $AssetDirectory 'install-aidipanel.sh'); Name = 'install-aidipanel.sh' },
            @{ Source = (Join-Path $sourceDirectory 'aidipanel'); Asset = (Join-Path $AssetDirectory 'aidipanel'); Name = 'aidipanel' }
        )
        foreach ($pair in $directFiles) {
            if (-not (Test-Path -LiteralPath $pair.Asset -PathType Leaf)) { throw "Missing release asset: $($pair.Name)" }
            $sourceHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $pair.Source).Hash
            $assetHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $pair.Asset).Hash
            if ($sourceHash -ne $assetHash) {
                throw "Release asset differs from the exact Git tag: $($pair.Name) (tag $sourceHash, asset $assetHash)"
            }
        }

        $expectedPanel = Get-RelativeFileMap -BasePath (Join-Path $sourceDirectory 'panel-app')
        $actualPanel = Get-RelativeFileMap -BasePath (Join-Path $releaseDirectory 'panel-app')
        Compare-Map -Expected $expectedPanel -Actual $actualPanel -Label 'Panel archive content'

        $sourceModes = Get-TarModeMap -Tar $tar -Archive $sourceArchive -Prefix 'panel-app/'
        $releaseModes = Get-TarModeMap -Tar $tar -Archive $releasePanelArchive -Compressed -Prefix 'panel-app/'
        Compare-Map -Expected $sourceModes -Actual $releaseModes -Label 'Panel archive mode'
    }
    finally {
        if (Test-Path -LiteralPath $temp) {
            Remove-Item -LiteralPath $temp -Recurse -Force
        }
    }
}

Export-ModuleMember -Function @(
    'Find-OpenSsl',
    'Assert-ReleaseVersion',
    'Read-ReleaseManifest',
    'Test-ManifestAssets',
    'Test-DetachedSignature',
    'Compare-ReleaseArtifactsToTag'
)
