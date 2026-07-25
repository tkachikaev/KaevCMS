#requires -Version 5.1
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    throw 'Node.js and npm are required for browser test setup.'
}

npm ci --include=dev
if ($LASTEXITCODE -ne 0) { throw "npm ci --include=dev failed with exit code $LASTEXITCODE." }

npm exec -- playwright install chromium
if ($LASTEXITCODE -ne 0) { throw "Playwright browser installation failed with exit code $LASTEXITCODE." }

Write-Host 'Browser test dependencies and Chromium were installed successfully.' -ForegroundColor Green
Write-Host 'Run .\deployment\windows\browser-quality.ps1 to execute the offline browser smoke tests.' -ForegroundColor DarkGray
