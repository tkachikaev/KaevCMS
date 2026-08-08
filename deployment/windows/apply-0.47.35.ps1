param(
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

$supportScript = Join-Path $PSScriptRoot 'support\release-update-support.ps1'
if (-not (Test-Path -LiteralPath $supportScript -PathType Leaf)) {
    throw 'Release update support script is missing. Re-extract the complete release or patch.'
}
. $supportScript

$release = Get-KaevCmsReleaseContract -ProjectRoot $ProjectRoot
$fromVersion = [string]$release.previous_version
$toVersion = [string]$release.version

if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'artisan') -PathType Leaf)) {
    throw 'The KaevCMS project root could not be found.'
}

if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot '.env') -PathType Leaf)) {
    throw '.env is missing. This patch is for an installed KaevCMS project. For a new hosting installation, open /install/.'
}

Assert-KaevCmsRequiredReleaseFiles `
    -ProjectRoot $ProjectRoot `
    -Remediation "Re-extract the complete $toVersion patch with file replacement enabled."

Write-Host "KaevCMS $fromVersion -> $toVersion update"
Write-Host 'This release fixes audit-log filter counter readability and spacing in administrator dark mode without changing the public website or player account.'
Write-Host ''

& (Join-Path $PSScriptRoot 'update.ps1') -SkipTests:$SkipTests

Write-Host ''
Write-Host "KaevCMS $toVersion is ready." -ForegroundColor Green
Write-Host 'Windows setup: .\deployment\windows\setup.ps1'
Write-Host 'Windows quality: .\deployment\windows\quality.ps1'
Write-Host 'Fresh installation only: /install/'
Write-Host 'Shared hosting updater: Administrator panel -> Settings -> System information -> Updates'
Write-Host 'Shared hosting package: .\deployment\windows\build-shared-hosting-package.ps1'
