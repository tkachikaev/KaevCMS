#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string] $PreviousFullArchive,

    [string] $OutputDirectory = ''
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP was not found in PATH.'
}

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw 'Composer was not found in PATH.'
}

php deployment/dependencies/dependency-guard.php
if ($LASTEXITCODE -ne 0) {
    throw 'Composer dependencies are not approved by the KaevCMS owner. See the message above.'
}

composer lint
if ($LASTEXITCODE -ne 0) {
    throw "Release lint preflight failed with exit code $LASTEXITCODE."
}

$builder = Join-Path $ProjectRoot 'deployment\release\build-release.php'
if (-not (Test-Path -LiteralPath $builder -PathType Leaf)) {
    throw 'The unified release builder is missing.'
}

$previous = Resolve-Path -LiteralPath $PreviousFullArchive -ErrorAction Stop
if (-not (Test-Path -LiteralPath $previous.Path -PathType Leaf)) {
    throw 'PreviousFullArchive must point to the direct previous full release ZIP.'
}

if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $ProjectRoot 'dist'
} elseif (-not [System.IO.Path]::IsPathRooted($OutputDirectory)) {
    $OutputDirectory = Join-Path $ProjectRoot $OutputDirectory
}
$OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)

# The cumulative Web Update carries a production vendor tree built from composer.lock, so
# sites coming from releases with other dependencies can be updated from the panel.
$vendorBuild = Join-Path ([System.IO.Path]::GetTempPath()) ('kaevcms-release-vendor-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $vendorBuild | Out-Null
try {
    foreach ($item in @('composer.json', 'composer.lock')) {
        Copy-Item -LiteralPath (Join-Path $ProjectRoot $item) -Destination $vendorBuild
    }
    # Optimized autoload classmaps include the application namespaces, so their sources must exist.
    foreach ($item in @('app', 'database\factories', 'database\seeders')) {
        $source = Join-Path $ProjectRoot $item
        if (Test-Path -LiteralPath $source) {
            $destination = Join-Path $vendorBuild $item
            New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force | Out-Null
            Copy-Item -LiteralPath $source -Destination $destination -Recurse
        }
    }

    Write-Host 'Preparing production Composer dependencies for the cumulative Web Update...' -ForegroundColor Cyan
    composer install --working-dir="$vendorBuild" --no-dev --no-scripts --optimize-autoloader --no-interaction --no-progress --prefer-dist
    if ($LASTEXITCODE -ne 0) {
        throw "Production Composer preparation failed with exit code $LASTEXITCODE."
    }

    php $builder `
        "--root=$ProjectRoot" `
        "--previous=$($previous.Path)" `
        "--output-dir=$OutputDirectory" `
        "--vendor=$(Join-Path $vendorBuild 'vendor')"

    if ($LASTEXITCODE -ne 0) {
        throw "Release build failed with exit code $LASTEXITCODE."
    }
} finally {
    if (Test-Path -LiteralPath $vendorBuild) {
        Remove-Item -LiteralPath $vendorBuild -Recurse -Force
    }
}

Write-Host "Release artifacts are ready in $OutputDirectory" -ForegroundColor Green
