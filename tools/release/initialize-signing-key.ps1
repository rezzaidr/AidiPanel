param(
    [string]$PrivateKeyPath = (Join-Path (Join-Path (Join-Path $HOME '.aidipanel') 'release-signing') 'release-signing-key.pem')
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$marker = '__AIDIPANEL_RELEASE_PUBLIC_KEY_B64__'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$module = Join-Path $PSScriptRoot 'ReleaseSigning.psm1'
$installer = Join-Path $root 'install.sh'
$cli = Join-Path $root 'aidipanel'
$publicKey = Join-Path $root 'config\release-signing-public.pub'

Import-Module $module -Force
$openssl = Find-OpenSsl

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

function Write-Utf8NoBom {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Content
    )

    [IO.File]::WriteAllText($Path, $Content, (New-Object Text.UTF8Encoding($false)))
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

function Set-PrivateKeyPermissions {
    param([Parameter(Mandatory)][string]$Path)

    if ($env:OS -eq 'Windows_NT') {
        $identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
        Invoke-InteractiveNative -Command (Join-Path $env:SystemRoot 'System32\icacls.exe') -Arguments @(
            $Path, '/inheritance:r', '/grant:r', "${identity}:(F)"
        ) -FailureMessage 'Could not restrict the private key ACL.'
        return
    }

    Invoke-InteractiveNative -Command 'chmod' -Arguments @('600', $Path) `
        -FailureMessage 'Could not restrict the private key permissions.'
}

$privateKeyFullPath = [IO.Path]::GetFullPath($PrivateKeyPath)
$privateKeyParent = [IO.Path]::GetDirectoryName($privateKeyFullPath)
$publicKeyParent = [IO.Path]::GetDirectoryName($publicKey)

if (-not $privateKeyParent) { throw 'The private key destination must have a parent directory.' }
if (Test-PathInsideRepository -Path $privateKeyParent -RepositoryRoot $root) {
    throw 'The private signing key must be stored outside the AidiPanel repository.'
}
if (Test-Path -LiteralPath $privateKeyFullPath) {
    throw "Private key destination already exists: $privateKeyFullPath"
}
if (Test-Path -LiteralPath $publicKey) {
    throw "Canonical public key already exists: $publicKey"
}

$installerOriginal = [IO.File]::ReadAllText($installer)
$cliOriginal = [IO.File]::ReadAllText($cli)
foreach ($target in @(
    @{ Name = 'install.sh'; Content = $installerOriginal },
    @{ Name = 'aidipanel'; Content = $cliOriginal }
)) {
    $markerCount = [regex]::Matches($target.Content, [regex]::Escape($marker)).Count
    if ($markerCount -ne 1) {
        throw "$($target.Name) must contain exactly one release public-key initialization marker; found $markerCount."
    }
}

[IO.Directory]::CreateDirectory($privateKeyParent) | Out-Null
[IO.Directory]::CreateDirectory($publicKeyParent) | Out-Null
$temporaryPrivateKey = Join-Path $privateKeyParent ('.release-signing-key.' + [guid]::NewGuid().ToString('N') + '.tmp')
$temporaryPublicKey = Join-Path $publicKeyParent ('.release-signing-public.' + [guid]::NewGuid().ToString('N') + '.tmp')
$installerNeedsRollback = $false
$cliNeedsRollback = $false
$publicKeyCreated = $false
$privateKeyMoved = $false

try {
    Write-Output 'OpenSSL will ask for a new private-key passphrase and confirmation.'
    Invoke-InteractiveNative -Command $openssl -Arguments @(
        'genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:P-256',
        '-aes-256-cbc', '-out', $temporaryPrivateKey
    ) -FailureMessage 'Private key generation failed.'

    Write-Output 'Enter the same passphrase once more so OpenSSL can derive the public key.'
    Invoke-InteractiveNative -Command $openssl -Arguments @(
        'pkey', '-in', $temporaryPrivateKey, '-pubout', '-out', $temporaryPublicKey
    ) -FailureMessage 'Public key derivation failed.'
    Invoke-InteractiveNative -Command $openssl -Arguments @(
        'pkey', '-pubin', '-in', $temporaryPublicKey, '-noout'
    ) -FailureMessage 'Generated public key validation failed.'

    $publicKeyBytes = [IO.File]::ReadAllBytes($temporaryPublicKey)
    $publicKeyBase64 = [Convert]::ToBase64String($publicKeyBytes)
    $installerUpdated = $installerOriginal.Replace($marker, $publicKeyBase64)
    $cliUpdated = $cliOriginal.Replace($marker, $publicKeyBase64)

    $installerNeedsRollback = $true
    Write-Utf8NoBom -Path $installer -Content $installerUpdated
    $cliNeedsRollback = $true
    Write-Utf8NoBom -Path $cli -Content $cliUpdated
    $publicKeyCreated = $true
    [IO.File]::WriteAllBytes($publicKey, $publicKeyBytes)
    [IO.File]::Move($temporaryPrivateKey, $privateKeyFullPath)
    $privateKeyMoved = $true
    Set-PrivateKeyPermissions -Path $privateKeyFullPath

    foreach ($targetPath in @($installer, $cli)) {
        $updatedContent = [IO.File]::ReadAllText($targetPath)
        $match = [regex]::Match(
            $updatedContent,
            '(?m)^readonly AIDIPANEL_RELEASE_PUBLIC_KEY_B64="([A-Za-z0-9+/]+={0,2})"$'
        )
        if (-not $match.Success) { throw "Embedded public key validation failed: $targetPath" }
        $decoded = [Convert]::FromBase64String($match.Groups[1].Value)
        if (@(Compare-Object -ReferenceObject $publicKeyBytes -DifferenceObject $decoded).Count -ne 0) {
            throw "Embedded public key differs from the canonical key: $targetPath"
        }
    }
    Invoke-InteractiveNative -Command $openssl -Arguments @(
        'pkey', '-pubin', '-in', $publicKey, '-noout'
    ) -FailureMessage 'Canonical public key validation failed.'

    $fingerprint = (Get-FileHash -Algorithm SHA256 -LiteralPath $publicKey).Hash.ToLowerInvariant()
    Write-Output "Public key: $publicKey"
    Write-Output "Public key SHA-256: $fingerprint"
    Write-Output "Encrypted private key: $privateKeyFullPath"
    Write-Output 'Signing-key initialization completed.'
}
catch {
    $originalError = $_
    $rollbackErrors = [System.Collections.Generic.List[string]]::new()

    if ($privateKeyMoved -and (Test-Path -LiteralPath $privateKeyFullPath)) {
        try { Remove-Item -LiteralPath $privateKeyFullPath -Force } catch { $rollbackErrors.Add($_.Exception.Message) }
    }
    if ($publicKeyCreated -and (Test-Path -LiteralPath $publicKey)) {
        try { Remove-Item -LiteralPath $publicKey -Force } catch { $rollbackErrors.Add($_.Exception.Message) }
    }
    if ($cliNeedsRollback) {
        try { Write-Utf8NoBom -Path $cli -Content $cliOriginal } catch { $rollbackErrors.Add($_.Exception.Message) }
    }
    if ($installerNeedsRollback) {
        try { Write-Utf8NoBom -Path $installer -Content $installerOriginal } catch { $rollbackErrors.Add($_.Exception.Message) }
    }

    if ($rollbackErrors.Count -ne 0) {
        throw "$($originalError.Exception.Message) Rollback also failed: $($rollbackErrors -join '; ')"
    }
    throw $originalError
}
finally {
    foreach ($temporaryPath in @($temporaryPrivateKey, $temporaryPublicKey)) {
        if (Test-Path -LiteralPath $temporaryPath) {
            Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        }
    }
}
