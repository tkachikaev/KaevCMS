param(
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

$fromVersion = '0.33.4'
$toVersion = '0.33.5'

if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'artisan') -PathType Leaf)) {
    throw 'The KaevCMS project root could not be found.'
}

if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot '.env') -PathType Leaf)) {
    throw '.env is missing. This patch is for an installed KaevCMS project. For a new hosting installation, open /install/.'
}

$versionPath = Join-Path $ProjectRoot 'VERSION'
if (-not (Test-Path -LiteralPath $versionPath -PathType Leaf)) {
    throw "VERSION is missing. Re-extract the complete $toVersion patch with file replacement enabled."
}

$cmsVersion = (Get-Content -LiteralPath $versionPath -Raw).Trim()
if ($cmsVersion -ne $toVersion) {
    throw "Unexpected patch version: $cmsVersion"
}

$requiredFiles = @(
    'CHANGELOG.md'
    'README.md'
    'VERSION'
    'app\Providers\AppServiceProvider.php'
    'app\Services\Infrastructure\PublicUploadProtection.php'
    'app\Services\SystemInformation.php'
    'app\Services\Updates\SystemUpdateInstaller.php'
    'app\Services\Updates\SystemUpdateRecovery.php'
    'app\Services\Updates\UpdateLock.php'
    'app\Services\Updates\UpdatePathPolicy.php'
    'deployment\hosting\README.md'
    'deployment\hosting\build-shared-hosting-package.php'
    'deployment\hosting\archive-shared-hosting-package.php'
    'deployment\hosting\shared-hosting\tests\layout-regression.php'
    'deployment\hosting\shared-hosting\tests\package-builder-regression.php'
    'deployment\hosting\shared-hosting\tests\update-entrypoint-regression.php'
    'deployment\hosting\web-installer\installer.php'
    'deployment\hosting\web-installer\tests\installer-regression.php'
    'deployment\updates\README.md'
    'deployment\updates\build-package.php'
    'deployment\updates\deletions.json'
    'deployment\updates\tests-package-builder.php'
    'deployment\vds\tests\documentation-regression.php'
    'deployment\windows\build-shared-hosting-package.ps1'
    'deployment\windows\build-web-update-package.ps1'
    'deployment\windows\update.ps1'
    'deployment\windows\support\release-update-support.ps1'
    'deployment\windows\tests\update-workflow.ps1'
    'deployment\windows\quality.ps1'
    'deployment\windows\browser-quality.ps1'
    'database\migrations\2026_07_23_000000_create_system_updates_table.php'
    'database\migrations\2026_07_23_010000_add_execution_state_to_system_updates_table.php'
    'database\seeders\BrowserTestSeeder.php'
    'docs\WEB_INSTALLER.md'
    'docs\en\INSTALLATION.md'
    'docs\en\SHARED_HOSTING.md'
    'docs\en\VDS_UBUNTU.md'
    'docs\ru\INSTALLATION.md'
    'docs\ru\SHARED_HOSTING.md'
    'docs\ru\VDS_UBUNTU.md'
    'docs\en\README.md'
    'docs\en\SECURITY.md'
    'docs\en\UPDATES.md'
    'docs\ru\README.md'
    'docs\ru\SECURITY.md'
    'docs\ru\UPDATES.md'
    'public\install\index.php'
    'public\index.php'
    'public\.htaccess'
    'deployment\hosting\shared-hosting\public\index.php'
    'deployment\hosting\shared-hosting\public\install\index.php'
    'deployment\hosting\shared-hosting\public\.htaccess'
    'resources\views\admin\settings\_system_tabs.blade.php'
    'tests\Feature\Admin\SystemSettingsTest.php'
    'tests\Feature\WebUpdaterReleaseTest.php'
    'tests\Unit\PublicUploadProtectionTest.php'
    'tests\Unit\Updates\UpdatePathPolicyTest.php'
    'tests\Feature\VdsDocumentationReleaseTest.php'
    'tests\Feature\WebInstallerReleaseTest.php'
    'modules\daily-rewards\module.json'
    'modules\daily-rewards\bootstrap.php'
    'modules\daily-rewards\routes\web.php'
    'modules\daily-rewards\routes\admin.php'
    'modules\daily-rewards\src\Services\DailyRewardClaimService.php'
    'modules\daily-rewards\src\Http\Controllers\AdminDailyRewardController.php'
    'modules\daily-rewards\src\Http\Controllers\DailyRewardController.php'
    'modules\daily-rewards\src\Http\Requests\StoreDailyRewardCalendarRequest.php'
    'modules\daily-rewards\src\Http\Requests\UpdateDailyRewardCalendarRequest.php'
    'modules\daily-rewards\src\Models\DailyRewardCalendar.php'
    'modules\daily-rewards\database\migrations\2026_07_25_000000_create_module_daily_reward_calendars_table.php'
    'modules\daily-rewards\database\migrations\2026_07_25_000100_create_module_daily_reward_days_table.php'
    'modules\daily-rewards\database\migrations\2026_07_25_000200_create_module_daily_reward_items_table.php'
    'modules\daily-rewards\database\migrations\2026_07_25_000300_create_module_daily_reward_claims_table.php'
    'modules\daily-rewards\resources\views\admin\edit.blade.php'
    'modules\daily-rewards\resources\views\admin\create.blade.php'
    'modules\daily-rewards\resources\views\admin\index.blade.php'
    'modules\daily-rewards\resources\views\account\index.blade.php'
    'modules\daily-rewards\lang\ru\messages.php'
    'modules\daily-rewards\lang\en\messages.php'
    'public\assets\admin\js\daily-rewards.js'
    'public\assets\admin\css\app.css'
    'public\assets\modules\daily-rewards.css'
    'resources\views\admin\themes\index.blade.php'
    'resources\views\admin\account-themes\index.blade.php'
    'resources\views\admin\modules\index.blade.php'
    'tests\Feature\Modules\DailyRewardsModuleTest.php'
    'tests\Feature\BrowserTestSeederTest.php'
    'tests\browser\specs\player-character-directory.spec.mjs'
    'tests\Feature\Admin\AdminPanelTest.php'
    'tests\Feature\Updates\SystemUpdateAdminTest.php'
    'tests\Unit\TranslationJsonTest.php'
    'tests\Feature\Admin\AdminThemeManagementTest.php'
    'tests\Feature\Admin\AdminAccountThemeManagementTest.php'
    'docs\ru\DAILY_REWARDS.md'
    'docs\en\DAILY_REWARDS.md'
)

foreach ($requiredFile in $requiredFiles) {
    $requiredPath = Join-Path $ProjectRoot $requiredFile
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Patch file is missing: $requiredFile. Re-extract the complete $toVersion patch with file replacement enabled."
    }
}

Write-Host "KaevCMS $fromVersion -> $toVersion update"
Write-Host 'This hotfix corrects the Daily Rewards browser assertion and restores Pint compliance for the new release files.'
Write-Host ''

& (Join-Path $PSScriptRoot 'update.ps1') -SkipTests:$SkipTests

Write-Host ''
Write-Host "KaevCMS $toVersion is ready." -ForegroundColor Green
Write-Host 'Windows setup: .\deployment\windows\setup.ps1'
Write-Host 'Windows quality: .\deployment\windows\quality.ps1'
Write-Host 'Web installer: /install/'
Write-Host 'Shared hosting updater: Administrator panel -> Settings -> System information -> Updates'
Write-Host 'VDS updater: php artisan kaevcms:update C:\path\to\KaevCMS-cumulative-update.zip'
Write-Host 'Shared hosting package: .\deployment\windows\build-shared-hosting-package.ps1'
Write-Host 'Composer/npm dependencies, database migrations, module versions, Promo Codes, and runtime reward logic were not changed.'
