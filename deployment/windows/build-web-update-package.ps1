#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$TargetReleaseRoot,
    [Parameter(Mandatory = $true)][string]$MinimumVersion,
    [Parameter(Mandatory = $true)][string]$MaximumVersion,
    [string]$PreviousReleaseRoot,
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

$supportScript = Join-Path $PSScriptRoot 'support\release-update-support.ps1'
if (-not (Test-Path -LiteralPath $supportScript -PathType Leaf)) {
    throw 'Release update support script is missing.'
}
. $supportScript

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP was not found in PATH.'
}

$targetReleaseRootFullPath = [System.IO.Path]::GetFullPath($TargetReleaseRoot)
$targetRelease = Get-KaevCmsReleaseContract -ProjectRoot $targetReleaseRootFullPath
Assert-KaevCmsRequiredReleaseFiles -ProjectRoot $targetReleaseRootFullPath
$targetVersion = [string]$targetRelease.version
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $ProjectRoot "dist\KaevCMS-cumulative-update-$MinimumVersion-$MaximumVersion-to-$targetVersion.zip"
}

$arguments = @(
    'deployment/updates/build-package.php'
    "--root=$TargetReleaseRoot"
    "--output=$OutputPath"
    "--minimum=$MinimumVersion"
    "--maximum=$MaximumVersion"
    "--target=$targetVersion"
    '--delete-file=deployment/updates/deletions.json'
)

if (-not [string]::IsNullOrWhiteSpace($PreviousReleaseRoot)) {
    $arguments += "--previous-root=$PreviousReleaseRoot"
    $arguments += '--update-history'
}

& php @arguments
if ($LASTEXITCODE -ne 0) {
    throw "Web update package build failed with exit code $LASTEXITCODE."
}
