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

php $builder `
    "--root=$ProjectRoot" `
    "--previous=$($previous.Path)" `
    "--output-dir=$OutputDirectory"

if ($LASTEXITCODE -ne 0) {
    throw "Release build failed with exit code $LASTEXITCODE."
}

Write-Host "Release artifacts are ready in $OutputDirectory" -ForegroundColor Green
