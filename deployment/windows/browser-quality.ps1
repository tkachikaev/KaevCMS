#requires -Version 5.1
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

if (-not (Get-Command node -ErrorAction SilentlyContinue) -or -not (Get-Command npm -ErrorAction SilentlyContinue)) {
    throw 'Node.js and npm are required for browser tests.'
}

if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'vendor\autoload.php'))) {
    throw 'Composer dependencies are missing. Run composer install first.'
}

$playwrightPackage = Join-Path $ProjectRoot 'node_modules\@playwright\test\package.json'
if (-not (Test-Path -LiteralPath $playwrightPackage -PathType Leaf)) {
    throw 'Browser test dependencies are missing. This is expected after extracting a fresh ZIP. Run .\deployment\windows\browser-setup.ps1 once while internet access is available.'
}

node --test tests/browser/support/navigation.test.mjs
if ($LASTEXITCODE -ne 0) { throw "Browser navigation helper tests failed with exit code $LASTEXITCODE." }

npm run test:browser
if ($LASTEXITCODE -ne 0) { throw "Browser tests failed with exit code $LASTEXITCODE." }

Write-Host 'Offline Playwright browser tests completed successfully.' -ForegroundColor Green
