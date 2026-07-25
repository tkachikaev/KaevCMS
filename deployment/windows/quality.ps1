#requires -Version 5.1
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw 'Composer was not found in PATH.'
}

if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'vendor\autoload.php'))) {
    throw 'Dependencies are not installed. Run composer install first.'
}

$supportScript = Join-Path $PSScriptRoot 'support\release-update-support.ps1'
if (-not (Test-Path -LiteralPath $supportScript -PathType Leaf)) {
    throw 'Release update support script is missing.'
}
. $supportScript
Initialize-KaevCmsRuntimeDirectories -ProjectRoot $ProjectRoot

# The regular quality gate is intentionally deterministic and offline.
# Dependency advisories are checked separately by .\deployment\windows\security-audit.ps1.
$composerNetworkVariable = Get-Item Env:COMPOSER_DISABLE_NETWORK -ErrorAction SilentlyContinue
$hadComposerNetworkSetting = $null -ne $composerNetworkVariable
$previousComposerNetworkSetting = if ($hadComposerNetworkSetting) {
    [string] $composerNetworkVariable.Value
} else {
    $null
}

try {
    $env:COMPOSER_DISABLE_NETWORK = '1'

    & "$PSScriptRoot\tests\update-workflow.ps1"
    & "$PSScriptRoot\tests\composer-audit-policy.ps1"

    php deployment/hosting/web-installer/tests/installer-regression.php
    if ($LASTEXITCODE -ne 0) { throw "Web installer regression checks failed with exit code $LASTEXITCODE." }

    php deployment/hosting/shared-hosting/tests/layout-regression.php
    if ($LASTEXITCODE -ne 0) { throw 'Shared-hosting layout regression failed.' }

    php deployment/hosting/shared-hosting/tests/update-entrypoint-regression.php
    if ($LASTEXITCODE -ne 0) { throw "Shared-hosting update entrypoint regression checks failed with exit code $LASTEXITCODE." }

    php deployment/hosting/shared-hosting/tests/package-builder-regression.php
    if ($LASTEXITCODE -ne 0) { throw "Shared-hosting package builder regression checks failed with exit code $LASTEXITCODE." }

    php deployment/updates/tests-package-builder.php
    if ($LASTEXITCODE -ne 0) { throw "Web update package builder regression checks failed with exit code $LASTEXITCODE." }

    php deployment/vds/tests/documentation-regression.php
    if ($LASTEXITCODE -ne 0) { throw "Ubuntu VDS documentation regression checks failed with exit code $LASTEXITCODE." }

    composer validate --strict --no-check-publish
    if ($LASTEXITCODE -ne 0) { throw "Composer validation failed with exit code $LASTEXITCODE." }

    composer quality
    if ($LASTEXITCODE -ne 0) { throw "Quality checks failed with exit code $LASTEXITCODE." }

    php artisan route:clear
    if ($LASTEXITCODE -ne 0) { throw "Route cache cleanup failed with exit code $LASTEXITCODE." }

    php artisan route:cache
    if ($LASTEXITCODE -ne 0) { throw "Route cache build failed with exit code $LASTEXITCODE." }

    php artisan route:clear
    if ($LASTEXITCODE -ne 0) { throw "Route cache cleanup failed with exit code $LASTEXITCODE." }

    Write-Host 'Offline quality checks completed successfully: PowerShell updater, audit policy, Web Installer, shared-hosting, Web Updater and Ubuntu VDS documentation regressions, Composer validation, Pint, PHPStan, PHPUnit and route cache.' -ForegroundColor Green
    Write-Host 'Run .\deployment\windows\security-audit.ps1 separately when internet access is available.' -ForegroundColor DarkGray
} finally {
    if ($hadComposerNetworkSetting) {
        $env:COMPOSER_DISABLE_NETWORK = $previousComposerNetworkSetting
    } else {
        Remove-Item Env:COMPOSER_DISABLE_NETWORK -ErrorAction SilentlyContinue
    }
}
