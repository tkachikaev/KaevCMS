param(
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

$fromVersion = '0.34.8'
$toVersion = '0.34.9'

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
    'public\index.php'
    'public\.htaccess'
    'public\install\index.php'
    'public\game-assets\README-RU.txt'
    'deployment\hosting\web-installer\installer.php'
    'deployment\hosting\web-installer\tests\installer-regression.php'
    'deployment\hosting\build-shared-hosting-package.php'
    'deployment\hosting\archive-shared-hosting-package.php'
    'deployment\hosting\shared-hosting\tests\layout-regression.php'
    'deployment\hosting\shared-hosting\tests\package-builder-regression.php'
    'deployment\hosting\shared-hosting\tests\update-entrypoint-regression.php'
    'deployment\windows\build-shared-hosting-package.ps1'
    'deployment\windows\update.ps1'
    'deployment\windows\support\release-update-support.ps1'
    'deployment\windows\tests\update-workflow.ps1'
    'deployment\updates\build-package.php'
    'deployment\updates\deletions.json'
    'deployment\updates\tests-package-builder.php'
    'app\Services\Updates\UpdatePathPolicy.php'
    'app\Services\Updates\SystemUpdateInstaller.php'
    'app\Services\Updates\SystemUpdateRecovery.php'
    'app\Services\Updates\UpdateLock.php'
    'app\Support\Modules\ModuleValidator.php'
    'app\Support\Modules\ModuleManager.php'
    'app\Http\Controllers\Admin\ModuleController.php'
    'routes\admin.php'
    'resources\views\admin\modules\index.blade.php'
    'resources\views\components\account-operation-modal.blade.php'
    'modules\daily-rewards\assets\module.webp'
    'modules\daily-rewards\module.json'
    'modules\daily-rewards\routes\admin.php'
    'modules\daily-rewards\src\Http\Controllers\AdminDailyRewardController.php'
    'modules\daily-rewards\src\Http\Controllers\DailyRewardController.php'
    'modules\daily-rewards\resources\views\admin\edit.blade.php'
    'modules\daily-rewards\lang\en\messages.php'
    'modules\daily-rewards\lang\ru\messages.php'
    'modules\promo-codes\assets\module.webp'
    'modules\promo-codes\module.json'
    'modules\promo-codes\src\Http\Controllers\PromoCodeController.php'
    'modules\promo-codes\lang\en\messages.php'
    'modules\promo-codes\lang\ru\messages.php'
    'account-themes\luxury\theme.json'
    'account-themes\luxury\views\layouts\app.blade.php'
    'account-themes\kaev-aurelia\theme.json'
    'account-themes\kaev-aurelia\views\layouts\app.blade.php'
    'public\account-themes\luxury\assets\css\app.css'
    'public\account-themes\luxury\assets\js\navigation.js'
    'public\account-themes\kaev-aurelia\assets\css\app.css'
    'public\account-themes\kaev-aurelia\assets\js\navigation.js'
    'public\assets\admin\css\app.css'
    'public\assets\admin\js\daily-rewards.js'
    'public\assets\modules\daily-rewards.css'
    'lang\en.json'
    'lang\ru.json'
    'modules\README.md'
    'docs\en\MODULES.md'
    'docs\ru\MODULES.md'
    'docs\en\DAILY_REWARDS.md'
    'docs\ru\DAILY_REWARDS.md'
    'tests\Feature\Account\AccountNavigationTest.php'
    'tests\Feature\BundledAureliaThemesTest.php'
    'tests\Feature\Modules\ModuleFoundationTest.php'
    'tests\Feature\Modules\DailyRewardsModuleTest.php'
    'tests\Feature\Modules\PromoCodesModuleTest.php'
    'tests\Feature\ReleaseMetadataTest.php'
    'tests\browser\specs\admin-navigation.spec.mjs'
    'tests\browser\specs\player-character-directory.spec.mjs'
    'resources\game-items\interlude.json'
    'resources\game-items\classic.json'
    'resources\game-items\high-five.json'
    'resources\game-items\shine-maker.json'
    'database\migrations\2026_07_23_000000_create_system_updates_table.php'
    'database\migrations\2026_07_23_010000_add_execution_state_to_system_updates_table.php'
    'resources\views\admin\settings\_system_tabs.blade.php'
)

foreach ($requiredFile in $requiredFiles) {
    $requiredPath = Join-Path $ProjectRoot $requiredFile
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Patch file is missing: $requiredFile. Re-extract the complete $toVersion patch with file replacement enabled."
    }
}

Write-Host "KaevCMS $fromVersion -> $toVersion update"
Write-Host 'This release resolves PHPStan and Playwright regressions from the module artwork and reward-dialog update.'
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
Write-Host 'Composer/npm dependencies, database migrations, module versions, account-theme versions, game drivers, item catalog data, external game-image paths, and reward-delivery schemas were not changed.'
