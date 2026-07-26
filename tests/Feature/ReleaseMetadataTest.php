<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReleaseMetadataTest extends TestCase
{
    public function test_release_metadata_matches_version_file(): void
    {
        $version = trim($this->readReleaseFile('VERSION'));

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/',
            $version
        );

        $previousVersion = $this->previousPatchVersion($version);

        $readme = $this->normalized($this->readReleaseFile('README.md'));
        $this->assertStringStartsWith("# KaevCMS {$version}\n", $readme);

        $changelog = $this->normalized($this->readReleaseFile('CHANGELOG.md'));
        $matched = preg_match(
            '/^##\s+(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)\s+-\s+\d{4}-\d{2}-\d{2}\s*$/m',
            $changelog,
            $matches
        );

        $this->assertSame(1, $matched, 'CHANGELOG must start with a dated release heading.');
        $this->assertSame($version, $matches[1] ?? null);

        $updateScript = $this->readReleaseFile('deployment/windows/update.ps1');
        $this->assertStringContainsString('$cmsVersion = (Get-Content \'VERSION\' -Raw).Trim()', $updateScript);
        $this->assertStringContainsString('Write-UpdateStage -Message "KaevCMS $($installed.Version) -> $cmsVersion update"', $updateScript);

        $applyScripts = glob(base_path('deployment/windows/apply-*.ps1')) ?: [];
        sort($applyScripts);

        $this->assertCount(1, $applyScripts, 'A release must contain exactly one current apply script.');
        $this->assertSame("apply-{$version}.ps1", basename($applyScripts[0]));

        $applyScript = (string) file_get_contents($applyScripts[0]);
        $this->assertStringContainsString("\$toVersion = '{$version}'", $applyScript);
        $this->assertStringContainsString("\$fromVersion = '{$previousVersion}'", $applyScript);
        $this->assertStringContainsString('public\install\index.php', $applyScript);
        $this->assertStringContainsString('deployment\hosting\web-installer\installer.php', $applyScript);
        $this->assertStringContainsString('deployment\hosting\web-installer\tests\installer-regression.php', $applyScript);
        $this->assertStringContainsString('deployment\hosting\build-shared-hosting-package.php', $applyScript);
        $this->assertStringContainsString('deployment\hosting\archive-shared-hosting-package.php', $applyScript);
        $this->assertStringContainsString('deployment\hosting\shared-hosting\tests\layout-regression.php', $applyScript);
        $this->assertStringContainsString('deployment\hosting\shared-hosting\tests\package-builder-regression.php', $applyScript);
        $this->assertStringContainsString('deployment\hosting\shared-hosting\tests\update-entrypoint-regression.php', $applyScript);
        $this->assertStringContainsString('public\index.php', $applyScript);
        $this->assertStringContainsString('public\.htaccess', $applyScript);
        $this->assertStringContainsString('app\Services\Updates\UpdatePathPolicy.php', $applyScript);
        $this->assertStringContainsString('resources\game-items\interlude.json', $applyScript);
        $this->assertStringContainsString('resources\game-items\classic.json', $applyScript);
        $this->assertStringContainsString('resources\game-items\high-five.json', $applyScript);
        $this->assertStringContainsString('resources\game-items\shine-maker.json', $applyScript);
        $this->assertStringContainsString('public\game-assets\README-RU.txt', $applyScript);
        $this->assertStringNotContainsString('public\game-assets\items', $applyScript);
        $this->assertStringNotContainsString('public\game-assets\characters', $applyScript);
        $this->assertStringContainsString('modules\daily-rewards\assets\module.webp', $applyScript);
        $this->assertStringContainsString('modules\promo-codes\assets\module.webp', $applyScript);
        $this->assertStringContainsString('deployment\windows\build-shared-hosting-package.ps1', $applyScript);
        $this->assertStringContainsString('app\Services\Updates\SystemUpdateInstaller.php', $applyScript);
        $this->assertStringContainsString('app\Services\Updates\SystemUpdateRecovery.php', $applyScript);
        $this->assertStringContainsString('app\Services\Updates\UpdateLock.php', $applyScript);
        $this->assertStringContainsString('deployment\updates\build-package.php', $applyScript);
        $this->assertStringContainsString('database\migrations\2026_07_23_000000_create_system_updates_table.php', $applyScript);
        $this->assertStringContainsString('database\migrations\2026_07_23_010000_add_execution_state_to_system_updates_table.php', $applyScript);
        $this->assertStringContainsString('resources\views\admin\settings\_system_tabs.blade.php', $applyScript);
        $this->assertStringContainsString('deployment\windows\tests\update-workflow.ps1', $applyScript);
        $this->assertStringContainsString('deployment\windows\support\release-update-support.ps1', $applyScript);
        $this->assertStringNotContainsString('Remove-Item -LiteralPath $obsoleteApplyScript.FullName', $applyScript);
        $this->assertStringNotContainsString('update.ps1 failed with exit code $LASTEXITCODE', $applyScript);

        $this->assertFileDoesNotExist(base_path('quality.ps1'));
        $this->assertFileDoesNotExist(base_path('setup.ps1'));
        $this->assertFileExists(base_path('deployment/windows/quality.ps1'));
        $this->assertFileExists(base_path('deployment/windows/setup.ps1'));
        $this->assertFileExists(base_path('bootstrap/cache/.gitignore'));

        $quality = $this->readReleaseFile('deployment/windows/quality.ps1');
        $this->assertStringContainsString('Initialize-KaevCmsRuntimeDirectories -ProjectRoot $ProjectRoot', $quality);
        $this->assertStringContainsString('update-entrypoint-regression.php', $quality);

        $runtimeSupport = $this->readReleaseFile('deployment/windows/support/release-update-support.ps1');
        $this->assertStringContainsString('function Initialize-KaevCmsRuntimeDirectories', $runtimeSupport);

        $browserQuality = $this->readReleaseFile('deployment/windows/browser-quality.ps1');
        $this->assertStringContainsString('node_modules\@playwright\test\package.json', $browserQuality);
        $this->assertStringNotContainsString('require.resolve(\'@playwright/test\')', $browserQuality);
    }

    public function test_account_navigation_test_uses_pint_compatible_single_quoted_literals(): void
    {
        $accountNavigationTest = $this->readReleaseFile('tests/Feature/Account/AccountNavigationTest.php');

        foreach (token_get_all($accountNavigationTest) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $this->assertFalse(
                str_starts_with($token[1], '"'),
                sprintf('Simple double-quoted literal remains on line %d: %s', $token[2], $token[1]),
            );
        }
    }

    public function test_documentation_is_bilingual_and_current(): void
    {
        foreach ([
            'docs/en/README.md',
            'docs/en/INSTALLATION.md',
            'docs/en/SHARED_HOSTING.md',
            'docs/en/SECURITY.md',
            'docs/ru/README.md',
            'docs/ru/INSTALLATION.md',
            'docs/ru/SHARED_HOSTING.md',
            'docs/ru/SECURITY.md',
        ] as $documentation) {
            $this->assertFileExists(base_path($documentation));
        }

        $englishHosting = $this->readReleaseFile('docs/en/SHARED_HOSTING.md');
        $russianHosting = $this->readReleaseFile('docs/ru/SHARED_HOSTING.md');
        $this->assertStringContainsString('-PublicDirectoryName', $englishHosting);
        $this->assertStringContainsString('-CoreDirectoryName', $englishHosting);
        $this->assertStringContainsString('-IncludeDevelopmentDependencies', $englishHosting);
        $this->assertStringContainsString('Beget', $englishHosting);
        $this->assertStringContainsString('Jino', $englishHosting);
        $this->assertStringContainsString('-PublicDirectoryName', $russianHosting);
        $this->assertStringContainsString('Beget', $russianHosting);
        $this->assertStringContainsString('Jino', $russianHosting);
        $this->assertStringContainsString('kaevcms-path.php', $englishHosting);
        $this->assertStringContainsString('public_path()', $englishHosting);
        $this->assertStringContainsString('kaevcms-path.php', $russianHosting);
        $this->assertStringContainsString('public_path()', $russianHosting);

        $documentationIndex = $this->readReleaseFile('docs/README.md');
        $this->assertStringContainsString('English documentation', $documentationIndex);
        $this->assertStringContainsString('Русская документация', $documentationIndex);
    }

    public function test_promo_code_reward_model_has_a_single_line_ending_at_eof(): void
    {
        $model = $this->readReleaseFile('modules/promo-codes/src/Models/PromoCodeReward.php');

        $this->assertStringEndsWith("\n", $model);
        $this->assertFalse(str_ends_with($model, "\n\n"));
        $this->assertFalse(str_ends_with($model, "\r\n\r\n"));
    }

    public function test_update_script_verifies_source_preserves_env_and_stages_cleanup_before_tests(): void
    {
        $version = trim($this->readReleaseFile('VERSION'));
        $previousVersion = $this->previousPatchVersion($version);
        $updateScript = $this->readReleaseFile('deployment/windows/update.ps1');

        $this->assertStringContainsString("\$expectedFromVersion = '{$previousVersion}'", $updateScript);
        $this->assertStringContainsString("\$expectedToVersion = '{$version}'", $updateScript);
        $this->assertStringContainsString(
            "\$legacyApplyScriptName = 'deployment\\windows\\apply-{$previousVersion}.ps1'",
            $updateScript,
        );
        $this->assertMatchesRegularExpression(
            '/^\$legacyApplySha256 = \'[a-f0-9]{64}\'\r?$/m',
            $updateScript,
        );
        $this->assertStringContainsString('Get-KaevCmsInstalledVersion', $updateScript);
        $this->assertStringContainsString('-ExpectedToVersion $expectedToVersion', $updateScript);
        $this->assertStringContainsString('legacyApplySha256', $updateScript);
        $this->assertStringContainsString('Write-KaevCmsPendingUpdateMarker', $updateScript);
        $this->assertStringContainsString('Convert-KaevCmsSupersededPendingUpdateMarker', $updateScript);
        $this->assertMatchesRegularExpression(
            '/^\$recoverableFromVersions = @\([^)]*\)\r?$/m',
            $updateScript,
        );
        $this->assertMatchesRegularExpression(
            '/^\$supersededPendingTargets = @\([^)]*\)\r?$/m',
            $updateScript,
        );
        $this->assertStringContainsString('if ($supersededPendingTargets.Count -gt 0)', $updateScript);
        $this->assertStringContainsString('$installed.Version -notin $supportedFromVersions', $updateScript);
        $this->assertStringContainsString('-FromVersion $installed.Version', $updateScript);
        $this->assertStringContainsString('Move-KaevCmsArtifactsToBackup', $updateScript);
        $this->assertStringContainsString('Remove-KaevCmsUpdateBackups', $updateScript);
        $this->assertStringNotContainsString('QUEUE_CONNECTION=sync', $updateScript);
        $this->assertStringNotContainsString('SESSION_COOKIE=l2forge_session', $updateScript);
        $this->assertStringNotContainsString('function Set-EnvValue', $updateScript);
        $this->assertStringContainsString('Clear-KaevCmsBootstrapCache -ProjectRoot $ProjectRoot', $updateScript);
        $this->assertStringContainsString('composer install --no-interaction --prefer-dist --no-scripts', $updateScript);
        $this->assertStringContainsString('$composerDependenciesChanged', $updateScript);
        $this->assertStringContainsString('Composer install was skipped', $updateScript);
        $this->assertStringContainsString('$actualComposerLockSha256 -ne $currentComposerLockSha256', $updateScript);
        $this->assertStringContainsString('php artisan queue:restart', $updateScript);
        $this->assertStringContainsString('php artisan kaevcms:maintenance-status --no-ansi', $updateScript);
        $this->assertStringContainsString('php artisan down --retry=60', $updateScript);
        $this->assertStringContainsString('finally {', $updateScript);
        $this->assertStringContainsString('php artisan up', $updateScript);
        $this->assertStringContainsString('php artisan kaevcms:release-version --mark=$cmsVersion', $updateScript);
        $this->assertStringContainsString('storage\app\installed.lock', $updateScript);
        $this->assertStringContainsString('\'resources\\views\\account\'', $updateScript);
        $this->assertStringContainsString('\'resources\\views\\livewire\\account\'', $updateScript);
        $this->assertStringContainsString('\'public\\assets\\account\'', $updateScript);
        $this->assertStringContainsString('\'integrations\\reward-queue\\remove-legacy-bridge.sql\'', $updateScript);

        $cachePosition = strpos($updateScript, 'Clear-KaevCmsBootstrapCache -ProjectRoot $ProjectRoot');
        $maintenancePosition = strpos($updateScript, 'php artisan down --retry=60');
        $composerPosition = strpos($updateScript, 'composer install --no-interaction --prefer-dist --no-scripts');
        $migrationPosition = strpos($updateScript, 'php artisan migrate --force');
        $queueRestartPosition = strpos($updateScript, 'php artisan queue:restart');
        $stagePosition = strpos($updateScript, 'Move-KaevCmsArtifactsToBackup');
        $testPosition = strpos($updateScript, 'php artisan test');
        $markPosition = strpos($updateScript, 'php artisan kaevcms:release-version --mark=$cmsVersion');
        $backupCleanupPosition = strpos($updateScript, 'Remove-KaevCmsUpdateBackups', $markPosition ?: 0);
        $finalCleanupPosition = strpos($updateScript, 'Remove-ObsoleteReleaseArtifacts -CurrentVersion $cmsVersion', $testPosition ?: 0);

        $this->assertNotFalse($cachePosition);
        $this->assertNotFalse($maintenancePosition);
        $this->assertNotFalse($composerPosition);
        $this->assertNotFalse($migrationPosition);
        $this->assertNotFalse($queueRestartPosition);
        $this->assertNotFalse($stagePosition);
        $this->assertNotFalse($testPosition);
        $this->assertNotFalse($markPosition);
        $this->assertNotFalse($backupCleanupPosition);
        $this->assertNotFalse($finalCleanupPosition);
        $this->assertLessThan($composerPosition, $cachePosition);
        $this->assertLessThan($composerPosition, $maintenancePosition);
        $this->assertLessThan($queueRestartPosition, $migrationPosition);
        $this->assertLessThan($testPosition, $queueRestartPosition);
        $this->assertLessThan($testPosition, $stagePosition);
        $this->assertLessThan($markPosition, $testPosition);
        $this->assertLessThan($backupCleanupPosition, $markPosition);
        $this->assertLessThan($finalCleanupPosition, $testPosition);

        $queueSql = $this->readReleaseFile('integrations/reward-queue/install.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `kaev_reward_queue`', $queueSql);
        $this->assertStringContainsString('`request_uuid` CHAR(36)', $queueSql);
        $this->assertStringContainsString('`item_id` BIGINT UNSIGNED', $queueSql);
        $this->assertStringContainsString('`amount` BIGINT UNSIGNED', $queueSql);

        $queueGateway = $this->readReleaseFile('app/Services/Rewards/DatabaseGameRewardQueueGateway.php');
        $this->assertStringContainsString('private const TABLE = \'kaev_reward_queue\'', $queueGateway);
        $this->assertStringContainsString('reward_queue_payload_conflict', $queueGateway);
        $this->assertStringNotContainsString('table(\'items\')', $queueGateway);

        $this->assertDirectoryDoesNotExist(base_path('integrations/mobius-interlude/reward-bridge'));
        $this->assertFileDoesNotExist(app_path('Jobs/ProcessRewardDelivery.php'));
        $this->assertFileDoesNotExist(app_path('Jobs/ConfirmRewardDelivery.php'));

        $phpunit = $this->readReleaseFile('phpunit.xml');
        $this->assertStringContainsString('<env name="APP_MAINTENANCE_DRIVER" value="cache" force="true"/>', $phpunit);
        $this->assertStringContainsString('<env name="APP_MAINTENANCE_STORE" value="array" force="true"/>', $phpunit);
        $this->assertStringNotContainsString('<env name="APP_MAINTENANCE_DRIVER" value="file"/>', $phpunit);

        $doctorScript = $this->readReleaseFile('deployment/windows/doctor.ps1');
        $this->assertStringContainsString('php artisan kaevcms:release-version --no-ansi', $doctorScript);
        $this->assertStringContainsString('php artisan kaevcms:encryption-health --no-ansi', $doctorScript);

        $qualityScript = $this->readReleaseFile('deployment/windows/quality.ps1');
        $this->assertStringContainsString('tests\\update-workflow.ps1', $qualityScript);
        $this->assertStringContainsString('tests\\composer-audit-policy.ps1', $qualityScript);
        $this->assertStringContainsString('deployment/hosting/shared-hosting/tests/layout-regression.php', $qualityScript);
        $this->assertStringContainsString('deployment/hosting/shared-hosting/tests/package-builder-regression.php', $qualityScript);
        $this->assertStringContainsString('$env:COMPOSER_DISABLE_NETWORK = \'1\'', $qualityScript);
        $this->assertStringContainsString('Remove-Item Env:COMPOSER_DISABLE_NETWORK', $qualityScript);
        $this->assertStringContainsString('finally {', $qualityScript);
        $this->assertStringNotContainsString('Invoke-KaevCmsComposerSecurityAudit', $qualityScript);
        $this->assertStringContainsString('php artisan route:cache', $qualityScript);
        $this->assertSame(2, substr_count($qualityScript, 'php artisan route:clear'));

        $securityAuditScript = $this->readReleaseFile('deployment/windows/security-audit.ps1');
        $this->assertStringContainsString('support\\composer-audit-support.ps1', $securityAuditScript);
        $this->assertStringContainsString('Invoke-KaevCmsComposerSecurityAudit', $securityAuditScript);
        $this->assertStringContainsString('npm audit --audit-level=high', $securityAuditScript);

        $composerAuditSupport = $this->readReleaseFile('deployment/windows/support/composer-audit-support.ps1');
        $this->assertStringContainsString(
            '$composerExecutable audit --locked --no-interaction',
            $composerAuditSupport,
        );
        $this->assertStringContainsString('Test-KaevCmsComposerAuditNetworkFailure', $composerAuditSupport);
        $this->assertStringContainsString('PSNativeCommandUseErrorActionPreference', $composerAuditSupport);
        $this->assertStringContainsString('Remove-Item Env:COMPOSER_DISABLE_NETWORK', $composerAuditSupport);
        $this->assertStringContainsString('System.Management.Automation.ErrorRecord', $composerAuditSupport);
        $this->assertStringContainsString('Dependency security has not been verified', $composerAuditSupport);
        $this->assertStringContainsString('throw "Composer security audit failed with exit code $auditExitCode."', $composerAuditSupport);

        $composerAuditPolicyTest = $this->readReleaseFile('deployment/windows/tests/composer-audit-policy.ps1');
        $this->assertStringContainsString('curl error 28', $composerAuditPolicyTest);
        $this->assertStringContainsString('security vulnerability advisory', $composerAuditPolicyTest);
        $this->assertStringContainsString('No security vulnerability advisories found.', $composerAuditPolicyTest);
        $this->assertStringContainsString('Network disabled, request canceled.', $composerAuditPolicyTest);
        $this->assertStringContainsString('NativeCommandError', $composerAuditPolicyTest);

        $browserQualityScript = $this->readReleaseFile('deployment/windows/browser-quality.ps1');
        $this->assertStringContainsString('node --test tests/browser/support/navigation.test.mjs', $browserQualityScript);
        $this->assertStringContainsString('npm run test:browser', $browserQualityScript);
        $this->assertStringNotContainsString('npm ci', $browserQualityScript);
        $this->assertStringNotContainsString('npm audit', $browserQualityScript);
        $this->assertStringNotContainsString('playwright install', $browserQualityScript);

        $browserSetupScript = $this->readReleaseFile('deployment/windows/browser-setup.ps1');
        $this->assertStringContainsString('npm ci --include=dev', $browserSetupScript);
        $this->assertStringContainsString('npm exec -- playwright install chromium', $browserSetupScript);

        $browserRunner = $this->readReleaseFile('tests/browser/run.mjs');
        $this->assertStringContainsString('findAvailablePort', $browserRunner);
        $this->assertStringContainsString('`--port=${browserPort}`', $browserRunner);

        $browserNavigation = $this->readReleaseFile('tests/browser/support/navigation.mjs');
        $this->assertStringContainsString('net::ERR_NO_BUFFER_SPACE', $browserNavigation);
        $this->assertStringContainsString('attempt <= 3', $browserNavigation);

        $browserNavigationTest = $this->readReleaseFile('tests/browser/support/navigation.test.mjs');
        $this->assertStringContainsString('ERR_NO_BUFFER_SPACE', $browserNavigationTest);
        $this->assertStringContainsString('does not retry application or unrelated browser failures', $browserNavigationTest);

        $workflow = $this->readReleaseFile('.github/workflows/quality.yml');
        $this->assertStringContainsString('composer audit --locked --no-interaction', $workflow);
        $this->assertStringContainsString('npm audit --audit-level=high', $workflow);
    }

    public function test_module_foundation_release_artifacts_are_shipped(): void
    {
        $this->assertFileExists(app_path('Providers/ModuleServiceProvider.php'));
        $this->assertFileExists(app_path('Http/Middleware/EnsureModuleEnabled.php'));
        $this->assertFileExists(app_path('Models/ModuleMigration.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleManager.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleMigrationManager.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleRuntime.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleValidator.php'));
        $this->assertFileExists(database_path('migrations/2026_07_20_000200_create_cms_modules_table.php'));
        $this->assertFileExists(database_path('migrations/2026_07_21_000000_add_module_migration_lifecycle.php'));
        $this->assertFileExists(resource_path('schemas/module.schema.json'));
        $this->assertFileExists(resource_path('views/admin/modules/index.blade.php'));
        $this->assertFileExists(base_path('modules/README.md'));
        $this->assertFileExists(base_path('docs/MODULES.md'));

        $schema = json_decode(
            $this->readReleaseFile('resources/schemas/module.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['schema', 'id', 'name', 'version', 'author'], $schema['required']);
        $this->assertSame(1, $schema['properties']['schema']['const']);
        $this->assertSame('#/$defs/relativePath', $schema['properties']['migrations']['$ref']);

        $migrationManager = $this->readReleaseFile('app/Support/Modules/ModuleMigrationManager.php');
        $this->assertStringContainsString('Cache::lock', $migrationManager);
        $this->assertStringContainsString('hash_file(\'sha256\'', $migrationManager);
        $this->assertStringContainsString('rollbackCurrentRun', $migrationManager);

        $moduleManager = $this->readReleaseFile('app/Support/Modules/ModuleManager.php');
        $this->assertStringContainsString('\'migration_pending\'', $moduleManager);
        $this->assertStringContainsString('\'migration_modified\'', $moduleManager);
        $this->assertStringContainsString('\'migration_error\'', $moduleManager);

        $runtime = $this->readReleaseFile('app/Support/Modules/ModuleRuntime.php');
        $this->assertStringContainsString('array_intersect([\'route:cache\', \'optimize\'], $arguments)', $runtime);

        $aureliaCss = $this->readReleaseFile('public/account-themes/kaev-aurelia/assets/css/app.css');
        $this->assertStringContainsString('display: grid; place-items: center;', $aureliaCss);
        $this->assertStringContainsString('.account-character-avatar > span', $aureliaCss);
        $this->assertStringContainsString('.account-surface {', $aureliaCss);
        $this->assertStringContainsString('Kaev Aurelia Account 1.3.0', $aureliaCss);
        $this->assertStringContainsString('.promo-activation-surface {', $aureliaCss);
        $this->assertStringContainsString('.reward-history-main p img {', $aureliaCss);
        $this->assertStringContainsString('Kaev Aurelia Account 1.3.1', $aureliaCss);
        $this->assertStringContainsString('Kaev Aurelia Account 1.4.0', $aureliaCss);
        $this->assertStringContainsString('.account-dashboard-tools {', $aureliaCss);
        $this->assertStringContainsString('border-radius: 24px;', $aureliaCss);

        $aureliaNavigation = $this->readReleaseFile('account-themes/kaev-aurelia/views/partials/navigation.blade.php');
        $this->assertStringContainsString('wire:current="active"', $aureliaNavigation);
        $this->assertStringNotContainsString('request()->routeIs(\'modules.\'', $aureliaNavigation);

        $aureliaInventory = $this->readReleaseFile('account-themes/kaev-aurelia/views/web-inventory/index.blade.php');
        $this->assertStringContainsString('account-surface reward-inventory-shell', $aureliaInventory);
    }

    public function test_web_inventory_release_artifacts_are_shipped(): void
    {
        $this->assertFileExists(app_path('Contracts/GameRewardQueueGateway.php'));
        $this->assertFileExists(app_path('Services/Rewards/DatabaseGameRewardQueueGateway.php'));
        $this->assertFileExists(app_path('Support/Rewards/RewardQueuePayload.php'));
        $this->assertFileExists(app_path('Models/RewardInventoryGrant.php'));
        $this->assertFileExists(app_path('Models/RewardInventoryItem.php'));
        $this->assertFileExists(app_path('Models/RewardDelivery.php'));
        $this->assertFileExists(app_path('Services/Rewards/RewardInventoryService.php'));
        $this->assertFileExists(app_path('Services/Rewards/RewardTransferService.php'));
        $this->assertFileExists(database_path('migrations/2026_07_21_000100_create_reward_inventory_tables.php'));
        $this->assertFileExists(base_path('docs/WEB_INVENTORY.md'));
        $this->assertFileExists(base_path('integrations/reward-queue/install.sql'));
        $this->assertFileExists(base_path('integrations/reward-queue/consumer-template.sql'));
        $this->assertFileExists(base_path('integrations/reward-queue/pending.sql'));
        $this->assertFileDoesNotExist(base_path('integrations/reward-queue/remove-legacy-bridge.sql'));
        $this->assertFileExists(base_path('account-themes/kaev-aurelia/views/web-inventory/index.blade.php'));
        $this->assertFileExists(base_path('account-themes/luxury/views/web-inventory/index.blade.php'));
        $this->assertFileExists(resource_path('views/admin/rewards/index.blade.php'));
        $this->assertFileExists(app_path('Services/Rewards/RewardDeliveryReconciler.php'));
        $this->assertFileExists(app_path('Console/Commands/ReconcileRewardDeliveriesCommand.php'));
        $this->assertFileExists(app_path('Http/Middleware/SecurityHeaders.php'));
        $this->assertFileExists(base_path('docs/AUDIT-0.30.0.md'));

        $contract = $this->readReleaseFile('app/Contracts/GameWorldDriver.php');
        $this->assertStringNotContainsString('rewardDeliveryCapabilities', $contract);
        $this->assertStringNotContainsString('deliverRewards', $contract);
        $this->assertStringNotContainsString('rewardDeliveryStatus', $contract);

        $queueContract = $this->readReleaseFile('app/Contracts/GameRewardQueueGateway.php');
        $this->assertStringContainsString('capabilities', $queueContract);
        $this->assertStringContainsString('enqueue', $queueContract);

        $mobiusDriver = $this->readReleaseFile('app/Services/GameWorld/MobiusGameWorldDriver.php');
        $mobiusRegistry = $this->readReleaseFile('app/Services/Servers/ServerDriverRegistry.php');
        $mobiusResolver = $this->readReleaseFile('app/Services/GameWorld/GameWorldDriverResolver.php');
        $mobiusAccounts = $this->readReleaseFile('app/Services/GameAccounts/ExternalGameAccountGateway.php');
        $this->assertStringNotContainsString('table(\'items\')', $mobiusDriver);
        $this->assertStringNotContainsString('reward', strtolower($mobiusDriver));
        $this->assertStringContainsString('$profile->reputationColumn', $mobiusDriver);
        $this->assertFileExists(app_path('Services/GameWorld/MobiusGameSchemaInspector.php'));
        $this->assertStringContainsString("public const MOBIUS_DRIVER = 'l2j_mobius';", $mobiusRegistry);
        $this->assertStringContainsString('MobiusGameSchemaInspector::requirements()', $mobiusRegistry);
        $this->assertStringNotContainsString('l2j_mobius_ct0_interlude', $mobiusRegistry);
        $this->assertStringNotContainsString('l2j_mobius_ct0_interlude', $mobiusResolver);
        $this->assertStringNotContainsString('l2j_mobius_ct0_interlude', $mobiusAccounts);
        $this->assertFileExists(database_path('migrations/2026_07_26_000000_rename_mobius_game_driver_identifier.php'));

        $homeController = $this->readReleaseFile('app/Http/Controllers/HomeController.php');
        $serverMonitor = $this->readReleaseFile('app/Services/Servers/ServerMonitor.php');
        $monitorCoordinator = $this->readReleaseFile('app/Services/Servers/ServerMonitorCoordinator.php');
        $this->assertStringContainsString('GameStatistics', $homeController);
        $this->assertStringNotContainsString('GameServerAdapter', $homeController);
        $this->assertStringNotContainsString('TheGreatPlayer', $homeController);
        $this->assertStringNotContainsString('\'message\' => $exception->getMessage()', $serverMonitor);
        $this->assertStringNotContainsString('\'message\' => $exception->getMessage()', $monitorCoordinator);
        $this->assertFileDoesNotExist(app_path('Contracts/GameServerAdapter.php'));
        $this->assertFileDoesNotExist(app_path('Services/GameServer/MobiusGameServerAdapter.php'));
        $this->assertFileDoesNotExist(app_path('Services/GameServer/MockGameServerAdapter.php'));
        $this->assertFileDoesNotExist(config_path('game.php'));
        $this->assertFileExists(app_path('Services/GameWorld/MobiusGameSchemaProfile.php'));
        $this->assertFileExists(app_path('Services/GameAccounts/MobiusClassNames.php'));
        $this->assertFileDoesNotExist(app_path('Services/GameWorld/MobiusInterludeGameWorldDriver.php'));
        $this->assertFileDoesNotExist(app_path('Services/GameAccounts/InterludeClassNames.php'));
        $this->assertFileDoesNotExist(app_path('Services/GameWorld/InterludeCharacterLabels.php'));

        $migration = $this->readReleaseFile('database/migrations/2026_07_21_000100_create_reward_inventory_tables.php');
        $this->assertStringContainsString('Schema::create(\'reward_inventory_grants\'', $migration);
        $this->assertStringContainsString('Schema::create(\'reward_inventory_items\'', $migration);
        $this->assertStringContainsString('Schema::create(\'reward_deliveries\'', $migration);
        $this->assertStringContainsString('Schema::create(\'reward_delivery_items\'', $migration);
        $this->assertStringContainsString('$table->timestamp(\'transferred_at\')->nullable()', $migration);
        $this->assertStringContainsString('$table->timestamp(\'queued_at\')->nullable()', $migration);

        $environment = $this->readReleaseFile('.env.example');
        $this->assertStringContainsString('APP_ENV=production', $environment);
        $this->assertStringContainsString('APP_DEBUG=false', $environment);
        $this->assertStringContainsString('LOG_LEVEL=warning', $environment);
        $this->assertStringNotContainsString('GAME_ADAPTER=', $environment);
        $this->assertStringNotContainsString('GAME_DB_HOST=', $environment);

        $schedule = $this->readReleaseFile('routes/console.php');
        $this->assertStringContainsString('kaevcms:rewards-reconcile', $schedule);
    }

    public function test_promo_code_and_game_asset_release_artifacts_are_shipped(): void
    {
        $this->assertFileExists(app_path('Services/GameAssets/GameAssetUrlResolver.php'));
        $this->assertFileExists(app_path('Services/GameAssets/GameItemCatalog.php'));
        $this->assertFileExists(app_path('Services/GameAssets/GameItemProfileCatalog.php'));
        $this->assertFileExists(lang_path('ru/items.php'));
        $this->assertFileExists(lang_path('en/items.php'));
        $this->assertFileExists(resource_path('game-items/interlude.json'));
        $this->assertFileExists(resource_path('game-items/classic.json'));
        $this->assertFileExists(resource_path('game-items/high-five.json'));
        $this->assertFileExists(resource_path('game-items/shine-maker.json'));
        $this->assertFileExists(public_path('game-assets/README-RU.txt'));
        $this->assertFileExists(public_path('game-assets/items/.gitkeep'));
        $this->assertFileExists(public_path('game-assets/items/interlude/.gitkeep'));
        $webUpdateBuilder = $this->readReleaseFile('deployment/updates/build-package.php');
        $this->assertStringContainsString('str_starts_with(strtolower($path), \'public/game-assets/\')', $webUpdateBuilder);
        $this->assertStringContainsString('(?:webp|png|jpe?g|gif|svg)', $webUpdateBuilder);

        $sharedHostingBuilder = $this->readReleaseFile('deployment/hosting/build-shared-hosting-package.php');
        $this->assertStringContainsString('str_starts_with(strtolower($path), \'game-assets/\')', $sharedHostingBuilder);
        $this->assertStringContainsString('(?:webp|png|jpe?g|gif|svg)', $sharedHostingBuilder);
        $this->assertFileExists(base_path('docs/GAME_ITEMS.md'));
        $this->assertFileExists(app_path('Services/GameAssets/CharacterAppearanceResolver.php'));
        $this->assertFileExists(config_path('character-appearances.php'));
        $this->assertFileExists(resource_path('views/components/game-character-avatar.blade.php'));
        $this->assertFileExists(base_path('docs/CHARACTER_AVATARS.md'));
        $this->assertFileExists(app_path('Support/Modules/ModuleNavigationRegistry.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleGameServerDependencyRegistry.php'));
        $this->assertFileExists(base_path('modules/promo-codes/module.json'));
        $this->assertFileExists(base_path('modules/promo-codes/bootstrap.php'));
        $this->assertFileExists(base_path('modules/promo-codes/database/migrations/2026_07_21_100000_create_module_promo_codes_table.php'));
        $this->assertFileExists(base_path('modules/promo-codes/database/migrations/2026_07_21_100100_create_module_promo_code_rewards_table.php'));
        $this->assertFileExists(base_path('modules/promo-codes/database/migrations/2026_07_21_100200_create_module_promo_code_activations_table.php'));
        $this->assertFileExists(base_path('modules/promo-codes/database/migrations/2026_07_21_100300_add_deleted_at_to_module_promo_codes_table.php'));
        $this->assertFileExists(base_path('modules/promo-codes/src/Services/PromoCodeActivationService.php'));
        $this->assertFileExists(base_path('docs/PROMO_CODES.md'));
        $this->assertFileExists(base_path('docs/GAME_ASSETS.md'));
        $this->assertFileExists(public_path('assets/admin/js/promo-codes.js'));
        $this->assertFileExists(public_path('assets/admin/css/app.css'));
        $this->assertFileExists(base_path('modules/promo-codes/assets/module.webp'));
        $this->assertFileExists(base_path('modules/daily-rewards/assets/module.webp'));
        $this->assertSame([512, 512], array_slice((array) getimagesize(base_path('modules/promo-codes/assets/module.webp')), 0, 2));
        $this->assertSame([512, 512], array_slice((array) getimagesize(base_path('modules/daily-rewards/assets/module.webp')), 0, 2));
        $this->assertFileExists(resource_path('views/components/account-operation-modal.blade.php'));

        $manifest = json_decode(
            $this->readReleaseFile('modules/promo-codes/module.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('promo-codes', $manifest['id']);
        $this->assertSame('1.1.0', $manifest['version']);
        $this->assertSame('0.34.8', $manifest['cms_min']);
        $this->assertSame('database/migrations', $manifest['migrations']);

        $activationService = $this->readReleaseFile('modules/promo-codes/src/Services/PromoCodeActivationService.php');
        $this->assertStringContainsString('lockForUpdate()', $activationService);
        $this->assertStringContainsString('grantKey: \'promo-code.activation.\'.$activation->id', $activationService);
        $this->assertStringContainsString('RewardInventoryService', $activationService);
        $this->assertStringNotContainsString('table(\'items\')', $activationService);

        $promoCodeModel = $this->readReleaseFile('modules/promo-codes/src/Models/PromoCode.php');
        $this->assertStringContainsString('use SoftDeletes;', $promoCodeModel);

        $promoScript = $this->readReleaseFile('public/assets/admin/js/promo-codes.js');
        $this->assertStringContainsString('data-promo-reward-add', $promoScript);
        $this->assertStringContainsString('data-promo-delete-form', $promoScript);

        $adminStyles = $this->readReleaseFile('public/assets/admin/css/app.css');
        $this->assertStringContainsString('.promo-reward-row', $adminStyles);
        $this->assertStringContainsString('.reward-queue-item', $adminStyles);
        $this->assertStringContainsString('.promo-reward-name-preview', $adminStyles);
        $this->assertStringContainsString('.module-mark > img', $adminStyles);

        $moduleValidator = $this->readReleaseFile('app/Support/Modules/ModuleValidator.php');
        $this->assertStringContainsString('\'assets/module.webp\'', $moduleValidator);
        $this->assertStringContainsString('$image[\'mime\'] !== \'image/webp\'', $moduleValidator);
        $moduleController = $this->readReleaseFile('app/Http/Controllers/Admin/ModuleController.php');
        $this->assertStringContainsString('\'X-Content-Type-Options\' => \'nosniff\'', $moduleController);

        $itemCatalog = $this->readReleaseFile('app/Services/GameAssets/GameItemCatalog.php');
        $this->assertStringContainsString('lang_path($locale.\'/items.php\')', $itemCatalog);
        $this->assertStringContainsString('$servers[$serverId]', $itemCatalog);
        $this->assertStringContainsString('fallbackCandidates', $itemCatalog);
        $this->assertStringContainsString('Lang::get(\'Game item\'', $itemCatalog);
        $this->assertStringContainsString('GameItemProfileCatalog', $itemCatalog);

        $interludeCatalog = json_decode(
            $this->readReleaseFile('resources/game-items/interlude.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(9208, $interludeCatalog);
        $this->assertSame('etc_adena_i00', $interludeCatalog[57]['icon']);
        $this->assertSame('Adena', $interludeCatalog[57]['name_en']);
        $this->assertSame('accessary_necklace_of_anguish_i00', $interludeCatalog[907]['icon']);
        $this->assertSame('Necklace of Anguish', $interludeCatalog[907]['name_en']);

        $classicCatalog = json_decode(
            $this->readReleaseFile('resources/game-items/classic.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $highFiveCatalog = json_decode(
            $this->readReleaseFile('resources/game-items/high-five.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $shineMakerCatalog = json_decode(
            $this->readReleaseFile('resources/game-items/shine-maker.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(19791, $classicCatalog);
        $this->assertCount(19198, $highFiveCatalog);
        $this->assertCount(19790, $shineMakerCatalog);
        $this->assertSame('etc_adena_i00', $classicCatalog[57]['icon']);
        $this->assertSame('etc_adena_i00', $highFiveCatalog[57]['icon']);
        $this->assertSame('etc_adena_i00', $shineMakerCatalog[57]['icon']);

        $rewardJournal = $this->readReleaseFile('resources/views/admin/rewards/index.blade.php');
        $this->assertStringContainsString('@section(\'title\', __(\'Reward queue\'))', $rewardJournal);
        $this->assertStringContainsString('audit-table reward-queue-table', $rewardJournal);
        $this->assertStringContainsString('ID {{ $item->item_id }}', $rewardJournal);

        $resolver = $this->readReleaseFile('app/Services/GameAssets/GameAssetUrlResolver.php');
        $this->assertStringContainsString('\'items\'', $resolver);
        $this->assertStringContainsString('\'characters\'', $resolver);
        $this->assertStringContainsString('firstCharacterAvatar', $resolver);
        $this->assertStringContainsString('str_starts_with($key', $resolver);
        $this->assertStringContainsString('\'webp\', \'png\', \'jpg\', \'jpeg\'', $resolver);
        $this->assertStringContainsString("'items/'.\$icon.'.webp'", $resolver);
        $this->assertStringContainsString("'items/'.\$profile.'/'.\$icon.'.webp'", $resolver);
        $this->assertStringContainsString('externalCharacterAvatar', $resolver);

        $appearanceResolver = $this->readReleaseFile('app/Services/GameAssets/CharacterAppearanceResolver.php');
        $this->assertStringContainsString('\'race_key\'', $appearanceResolver);
        $this->assertStringContainsString('\'gender_key\'', $appearanceResolver);
        $this->assertStringContainsString('\'archetype\'', $appearanceResolver);
        $this->assertStringContainsString('fallback/neutral/default', $appearanceResolver);

        $avatarGuide = $this->readReleaseFile('docs/CHARACTER_AVATARS.md');
        $this->assertStringContainsString('characters/{profile}/human/female/mage.webp', $avatarGuide);
        $this->assertStringContainsString('characters/{profile}/fallback/neutral/default.webp', $avatarGuide);
        foreach (['interlude', 'classic', 'high-five', 'shine-maker'] as $profile) {
            $this->assertFileExists(public_path("game-assets/characters/{$profile}/human/female/.gitkeep"));
            $this->assertFileExists(public_path("game-assets/characters/{$profile}/fallback/neutral/.gitkeep"));
        }
    }

    public function test_account_avatar_release_artifacts_are_shipped(): void
    {
        $this->assertFileExists(app_path('Services/Account/AccountAvatarCatalog.php'));
        $this->assertFileExists(app_path('Http/Controllers/Account/ProfileController.php'));
        $this->assertFileExists(app_path('Http/Controllers/Account/SecurityController.php'));
        $this->assertFileExists(app_path('Http/Requests/Account/UpdateAccountAvatarRequest.php'));
        $this->assertFileExists(database_path('migrations/2026_07_22_000100_add_account_avatar_to_users_table.php'));
        $this->assertFileExists(resource_path('views/components/account-avatar.blade.php'));
        $this->assertFileExists(resource_path('views/components/account-avatar-modal.blade.php'));
        $this->assertFileExists(resource_path('views/components/account-operation-modal.blade.php'));
        $this->assertFileExists(resource_path('views/components/game-account-icon.blade.php'));
        $this->assertFileExists(app_path('Http/Controllers/Account/CharacterController.php'));
        $this->assertFileExists(database_path('migrations/2026_07_22_000200_upgrade_character_directory_preferences.php'));
        $this->assertFileExists(base_path('account-themes/kaev-aurelia/views/characters/index.blade.php'));
        $this->assertFileExists(base_path('account-themes/luxury/views/characters/index.blade.php'));
        $this->assertFileExists(base_path('account-themes/kaev-aurelia/views/profile/edit.blade.php'));
        $this->assertFileExists(base_path('account-themes/luxury/views/profile/edit.blade.php'));
        $this->assertFileExists(base_path('account-themes/kaev-aurelia/views/security/edit.blade.php'));
        $this->assertFileExists(base_path('account-themes/luxury/views/security/edit.blade.php'));
        $this->assertFileExists(base_path('account-themes/kaev-aurelia/views/partials/settings-tabs.blade.php'));
        $this->assertFileExists(base_path('account-themes/luxury/views/partials/settings-tabs.blade.php'));
        $this->assertFileExists(base_path('docs/ACCOUNT_AVATARS.md'));

        $routes = $this->readReleaseFile('routes/account.php');
        $this->assertStringContainsString('/account/profile', $routes);
        $this->assertStringContainsString('/account/security', $routes);
        $this->assertStringContainsString('security.password.update', $routes);
        $this->assertStringContainsString('/account/characters', $routes);
        $this->assertStringContainsString('characters.index', $routes);
        $this->assertStringContainsString('profile.avatar.update', $routes);

        $setup = $this->readReleaseFile('deployment/windows/setup.ps1');
        $update = $this->readReleaseFile('deployment/windows/update.ps1');
        $doctor = $this->readReleaseFile('deployment/windows/doctor.ps1');
        $this->assertStringContainsString('public\uploads\account-avatars', $setup);
        $this->assertStringContainsString('public\uploads\account-avatars', $update);
        $this->assertStringContainsString('Account avatar directory', $doctor);

        $migration = $this->readReleaseFile('database/migrations/2026_07_22_000100_add_account_avatar_to_users_table.php');
        $this->assertStringContainsString('$table->string(\'avatar_filename\', 190)->nullable()', $migration);

        $component = $this->readReleaseFile('resources/views/components/account-avatar.blade.php');
        $this->assertStringContainsString('AccountAvatarCatalog::class', $component);
        $this->assertStringContainsString('data-account-avatar', $component);

        $modal = $this->readReleaseFile('resources/views/components/account-avatar-modal.blade.php');
        $this->assertStringContainsString('data-avatar-modal', $modal);
        $this->assertStringContainsString('return_to', $modal);
        $this->assertStringContainsString('AccountAvatarCatalog::class', $modal);

        $preferenceMigration = $this->readReleaseFile('database/migrations/2026_07_22_000200_upgrade_character_directory_preferences.php');
        $this->assertStringContainsString('$table->unsignedSmallInteger(\'schema_version\')->default(1)', $preferenceMigration);
        $this->assertStringContainsString('\'view_mode\' => \'all\'', $preferenceMigration);
        $this->assertStringContainsString('\'schema_version\' => 2', $preferenceMigration);
    }

    public function test_obsolete_preview_and_settings_placeholder_are_not_shipped(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('preview'));
        $this->assertFileDoesNotExist(resource_path('views/admin/settings/placeholder.blade.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Admin/SettingsController.php'));
        $this->assertFileExists(base_path('routes/public.php'));
        $this->assertFileExists(base_path('routes/account.php'));
        $this->assertFileExists(base_path('routes/admin.php'));
    }

    private function readReleaseFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));

        if ($contents === false) {
            $this->fail("Unable to read release file: {$path}");
        }

        return $contents;
    }

    private function previousPatchVersion(string $version): string
    {
        $matched = preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches);

        $this->assertSame(1, $matched, 'Release version must contain major, minor and patch components.');

        $patch = (int) ($matches[3] ?? 0);
        if ($patch > 0) {
            return sprintf(
                '%d.%d.%d',
                (int) ($matches[1] ?? 0),
                (int) ($matches[2] ?? 0),
                $patch - 1,
            );
        }

        $matched = preg_match_all(
            '/^##\s+(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)\s+-\s+\d{4}-\d{2}-\d{2}\s*$/m',
            $this->normalized($this->readReleaseFile('CHANGELOG.md')),
            $releases,
        );
        $this->assertGreaterThanOrEqual(2, $matched, 'A minor release must follow a documented stable release.');
        $this->assertSame($version, $releases[1][0] ?? null);

        return (string) ($releases[1][1] ?? '');
    }

    private function normalized(string $contents): string
    {
        return str_replace("\r\n", "\n", $contents);
    }
}
