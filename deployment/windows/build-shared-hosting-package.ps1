param(
    [string]$PublicDirectoryName = 'public_html',
    [string]$CoreDirectoryName = 'kaevcms-core',
    [string]$OutputDirectory = 'dist',
    [switch]$IncludeDevelopmentDependencies
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

$builder = Join-Path $ProjectRoot 'deployment\hosting\build-shared-hosting-package.php'
$archiver = Join-Path $ProjectRoot 'deployment\hosting\archive-shared-hosting-package.php'
foreach ($requiredScript in @($builder, $archiver)) {
    if (-not (Test-Path -LiteralPath $requiredScript -PathType Leaf)) {
        throw "Shared-hosting packaging script is missing: $requiredScript"
    }
}
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP CLI was not found in PATH.'
}
if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'vendor\autoload.php') -PathType Leaf)) {
    throw 'vendor\autoload.php is missing. Run setup.ps1 or Composer first.'
}
if (-not $IncludeDevelopmentDependencies -and -not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw 'Composer was not found in PATH. It is required to remove development dependencies from the production package. Use -IncludeDevelopmentDependencies only for a temporary test archive.'
}

$version = (Get-Content -LiteralPath (Join-Path $ProjectRoot 'VERSION') -Raw).Trim()
$outputRoot = if ([System.IO.Path]::IsPathRooted($OutputDirectory)) {
    [System.IO.Path]::GetFullPath($OutputDirectory)
} else {
    [System.IO.Path]::GetFullPath((Join-Path $ProjectRoot $OutputDirectory))
}
$packageDirectory = Join-Path $outputRoot "KaevCMS-$version-shared-hosting"
$coreDirectory = Join-Path $packageDirectory $CoreDirectoryName
$zipPath = Join-Path $outputRoot "KaevCMS-$version-shared-hosting.zip"
$shaPath = "$zipPath.sha256"

php $builder `
    "--public-dir=$PublicDirectoryName" `
    "--core-dir=$CoreDirectoryName" `
    "--output=$OutputDirectory" `
    '--no-zip'

if ($LASTEXITCODE -ne 0) {
    throw "Shared-hosting package preparation failed with exit code $LASTEXITCODE."
}
if (-not (Test-Path -LiteralPath $packageDirectory -PathType Container)) {
    throw "Prepared package directory was not found: $packageDirectory"
}
if (-not (Test-Path -LiteralPath (Join-Path $coreDirectory 'composer.json') -PathType Leaf)) {
    throw "Prepared package core is incomplete: $coreDirectory"
}

if ($IncludeDevelopmentDependencies) {
    Write-Warning 'Development dependencies are included. This switch is intended only for temporary testing.'
} else {
    Write-Host 'Preparing production Composer dependencies from a clean vendor directory...' -ForegroundColor Cyan
    $packageVendor = Join-Path $coreDirectory 'vendor'
    if (Test-Path -LiteralPath $packageVendor) {
        Remove-Item -LiteralPath $packageVendor -Recurse -Force
    }

    & composer install `
        "--working-dir=$coreDirectory" `
        '--no-dev' `
        '--optimize-autoloader' `
        '--no-interaction' `
        '--no-progress' `
        '--prefer-dist' `
        '--no-scripts'

    if ($LASTEXITCODE -ne 0) {
        throw "Production Composer preparation failed with exit code $LASTEXITCODE."
    }

    foreach ($developmentPackage in @('vendor\phpunit', 'vendor\phpstan', 'vendor\larastan', 'vendor\fakerphp', 'vendor\mockery', 'vendor\nunomaduro\collision')) {
        if (Test-Path -LiteralPath (Join-Path $coreDirectory $developmentPackage)) {
            throw "Development dependency remained in the production package: $developmentPackage"
        }
    }
}

php $archiver `
    "--package=$packageDirectory" `
    "--zip=$zipPath"

if ($LASTEXITCODE -ne 0) {
    throw "Shared-hosting archive creation failed with exit code $LASTEXITCODE."
}
if (-not (Test-Path -LiteralPath $zipPath -PathType Leaf)) {
    throw 'Shared-hosting ZIP was not created. Enable the PHP zip extension and run the builder again.'
}
if (-not (Test-Path -LiteralPath $shaPath -PathType Leaf)) {
    throw "Shared-hosting SHA256 file was not created: $shaPath"
}

$shaLine = (Get-Content -LiteralPath $shaPath -Raw).Trim()
if ($shaLine -notmatch '^([a-fA-F0-9]{64})\s{2}(.+)$') {
    throw 'Shared-hosting SHA256 file has an invalid format.'
}
$expectedHash = $Matches[1].ToLowerInvariant()
$expectedFileName = $Matches[2].Trim()
if ($expectedFileName -ne [System.IO.Path]::GetFileName($zipPath)) {
    throw 'Shared-hosting SHA256 file references an unexpected archive name.'
}
$actualHash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actualHash -ne $expectedHash) {
    throw 'Shared-hosting ZIP SHA256 does not match the generated checksum.'
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $entries = @($archive.Entries)
    if ($entries.Count -eq 0) {
        throw 'Shared-hosting ZIP is empty.'
    }

    foreach ($entry in $entries) {
        $entryName = $entry.FullName
        if ($entryName -match '\\') {
            throw "Shared-hosting ZIP contains a Windows path separator: $entryName"
        }
        if ($entryName.StartsWith('/') -or $entryName -match '(^|/)\.\.(/|$)') {
            throw "Shared-hosting ZIP contains an unsafe path: $entryName"
        }
        if ($entryName -eq 'INSTALL-SHARED-HOSTING.txt') {
            continue
        }
        if (-not ($entryName.StartsWith("$CoreDirectoryName/") -or $entryName.StartsWith("$PublicDirectoryName/"))) {
            throw "Shared-hosting ZIP contains an unexpected top-level entry: $entryName"
        }
    }
} finally {
    $archive.Dispose()
}

Write-Host ''
Write-Host "Shared-hosting archive: $zipPath" -ForegroundColor Green
Write-Host "SHA256: $shaPath"
Write-Host "Public directory in archive: $PublicDirectoryName"
Write-Host "Private core directory in archive: $CoreDirectoryName"
Write-Host "Development dependencies included: $($IncludeDevelopmentDependencies.IsPresent)"
