#requires -Version 5.1
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw 'Composer was not found in PATH.'
}

if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    throw 'Node.js and npm are required for the npm security audit.'
}

if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'composer.lock'))) {
    throw 'composer.lock is missing.'
}

if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'package-lock.json'))) {
    throw 'package-lock.json is missing.'
}

. "$PSScriptRoot\support\composer-audit-support.ps1"

Write-Host 'Checking Composer dependencies against the current Packagist advisory database...'
$composerAuditCompleted = Invoke-KaevCmsComposerSecurityAudit
if (-not $composerAuditCompleted) {
    throw 'Composer dependency security audit was not completed. Check internet and DNS access, then run .\deployment\windows\security-audit.ps1 again.'
}

Write-Host ''
Write-Host 'Checking npm dependencies against the current npm advisory database...'
npm audit --audit-level=high
if ($LASTEXITCODE -ne 0) { throw "npm security audit failed with exit code $LASTEXITCODE." }

Write-Host 'Composer and npm dependency security audits completed successfully.' -ForegroundColor Green
