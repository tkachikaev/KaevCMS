<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Tests\TestCase;

class ReleaseMetadataTest extends TestCase
{
    public function test_release_metadata_matches_version_file(): void
    {
        $release = $this->releaseContract();
        $version = (string) $release['version'];
        $previousVersion = (string) $release['previous_version'];

        $this->assertSame(1, $release['schema']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $previousVersion);
        $this->assertTrue(version_compare((string) $release['cumulative_base_version'], (string) $release['recovery_floor_version'], '<='));
        $this->assertTrue(version_compare((string) $release['recovery_floor_version'], $previousVersion, '<='));
        $this->assertTrue(version_compare($previousVersion, $version, '<'));
        $releaseDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $release['released_at']);
        $this->assertInstanceOf(DateTimeImmutable::class, $releaseDate);
        $this->assertSame((string) $release['released_at'], $releaseDate->format('Y-m-d'));
        $this->assertSame($version, trim($this->readReleaseFile('VERSION')));
        $this->assertSame("deployment/windows/apply-{$version}.ps1", $release['apply_script']);
        $this->assertSame('deployment/windows/update.ps1', $release['update_script']);
        $this->assertSame("deployment/windows/apply-{$previousVersion}.ps1", $release['previous_apply_script']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $release['previous_apply_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $release['composer_lock']['previous_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $release['composer_lock']['current_sha256']);

        $repairFiles = $release['repair_files'] ?? null;
        $this->assertIsArray($repairFiles);
        $this->assertSame($repairFiles, array_values(array_unique($repairFiles)));
        $sortedRepairFiles = $repairFiles;
        sort($sortedRepairFiles);
        $this->assertSame($sortedRepairFiles, $repairFiles);
        foreach ($repairFiles as $repairFile) {
            $this->assertIsString($repairFile);
            $this->assertNotSame('', $repairFile);
            $this->assertStringNotContainsString('..', $repairFile);
            $this->assertStringNotContainsString('\\', $repairFile);
            $this->assertFalse(str_starts_with($repairFile, '/'));
            $this->assertFileExists(base_path($repairFile), 'Repair file is missing from the release tree: '.$repairFile);
        }
        $this->assertSame(
            hash_file('sha256', base_path('composer.lock')),
            $release['composer_lock']['current_sha256'],
        );

        $readme = $this->normalized($this->readReleaseFile('README.md'));
        $this->assertStringStartsWith("# KaevCMS {$version}\n", $readme);

        $changelog = $this->normalized($this->readReleaseFile('CHANGELOG.md'));
        $matched = preg_match(
            '/^##\s+(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)\s+-\s+\d{4}-\d{2}-\d{2}\s*$/m',
            $changelog,
            $matches,
        );
        $this->assertSame(1, $matched, 'CHANGELOG must start with a dated release heading.');
        $this->assertSame($version, $matches[1] ?? null);

        $applyScripts = glob(base_path('deployment/windows/apply-*.ps1')) ?: [];
        sort($applyScripts);
        $this->assertCount(1, $applyScripts, 'A release must contain exactly one current apply script.');
        $this->assertSame(basename((string) $release['apply_script']), basename($applyScripts[0]));

        $manifest = $this->releaseFileManifest();
        $requiredFiles = $manifest['required_files'];
        $this->assertSame(1, $manifest['schema']);
        $this->assertNotEmpty($requiredFiles);
        $this->assertSame($requiredFiles, array_values(array_unique($requiredFiles)));
        $sortedRequiredFiles = $requiredFiles;
        sort($sortedRequiredFiles);
        $this->assertSame($sortedRequiredFiles, $requiredFiles);
        $this->assertContains('release.json', $requiredFiles);
        $this->assertContains('deployment/release-files.json', $requiredFiles);
        $this->assertContains('deployment/windows/update-contract.json', $requiredFiles);
        $this->assertContains((string) $release['apply_script'], $requiredFiles);

        foreach ($requiredFiles as $requiredFile) {
            $this->assertIsString($requiredFile);
            $this->assertStringNotContainsString('..', $requiredFile);
            $this->assertFalse(str_starts_with($requiredFile, '/'));
            $this->assertFileExists(base_path($requiredFile), "Required release file is missing: {$requiredFile}");
        }

        $deletions = $this->jsonReleaseFile('deployment/updates/deletions.json');
        $this->assertArrayHasKey($version, $deletions);
        $this->assertContains('core/'.(string) $release['previous_apply_script'], $deletions[$version]);
        $this->assertNotContains('public/assets/account', $deletions[$version]);

        $this->assertFileDoesNotExist(base_path('quality.ps1'));
        $this->assertFileDoesNotExist(base_path('setup.ps1'));
        $this->assertFileExists(base_path('deployment/windows/quality.ps1'));
        $this->assertFileExists(base_path('deployment/windows/setup.ps1'));
        $this->assertFileExists(base_path('deployment/windows/tests/update-workflow.ps1'));
        $this->assertFileExists(base_path('deployment/windows/support/release-update-support.ps1'));
        $this->assertFileExists(base_path('deployment/windows/build-release.ps1'));
        $this->assertFileExists(base_path('deployment/release/build-release.php'));
        $this->assertFileExists(base_path('deployment/release/tests/release-builder-regression.php'));
        $this->assertFileExists(base_path('bootstrap/cache/.gitignore'));
    }

    public function test_dependency_lock_preserves_portable_line_endings(): void
    {
        $this->assertStringNotContainsString("\r", $this->readReleaseFile('composer.lock'));
    }

    public function test_phpstan_analyzes_every_bundled_module_source_directory(): void
    {
        $configuration = $this->normalized($this->readReleaseFile('phpstan.neon'));
        $moduleManifests = glob(base_path('modules/*/module.json')) ?: [];

        $this->assertStringNotContainsString('treatPhpDocTypesAsCertain', $configuration);
        $this->assertStringNotContainsString('excludePaths:', $configuration);
        $this->assertNotEmpty($moduleManifests);

        foreach ($moduleManifests as $manifestPath) {
            $projectRoot = str_replace('\\', '/', base_path());
            $moduleDirectory = str_replace('\\', '/', dirname($manifestPath));
            $relativeModuleDirectory = ltrim(str_replace($projectRoot, '', $moduleDirectory), '/');
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

            $this->assertIsArray($manifest);
            $autoload = trim((string) ($manifest['autoload'] ?? ''));
            $this->assertNotSame('', $autoload);

            $sourcePath = $relativeModuleDirectory.'/'.trim(str_replace('\\', '/', $autoload), '/');
            $this->assertDirectoryExists(base_path($sourcePath));
            $this->assertStringContainsString("        - {$sourcePath}\n", $configuration);
        }
    }

    public function test_security_fixed_http_client_versions_are_locked(): void
    {
        $lock = $this->jsonReleaseFile('composer.lock');
        $packages = collect($lock['packages'] ?? [])->keyBy('name');
        $guzzle = $packages->get('guzzlehttp/guzzle');
        $psr7 = $packages->get('guzzlehttp/psr7');

        $this->assertIsArray($guzzle);
        $this->assertIsArray($psr7);
        $this->assertTrue(version_compare((string) ($guzzle['version'] ?? '0.0.0'), '7.15.2', '>='));
        $this->assertTrue(version_compare((string) ($psr7['version'] ?? '0.0.0'), '2.13.0', '>='));
    }

    public function test_runtime_dependency_lock_contains_security_fixed_versions(): void
    {
        $lock = $this->jsonReleaseFile('composer.lock');
        $packages = collect(array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []))->keyBy('name');
        // Fixed versions from the vendors' published 2026 security advisories.
        $minimumVersions = [
            'laravel/framework' => '13.30.0',
            'league/commonmark' => '2.10.2',
            'league/flysystem' => '3.35.3',
            'livewire/livewire' => '4.3.4',
            'symfony/http-foundation' => '7.4.19',
            'symfony/yaml' => '7.4.18',
        ];
        foreach ($minimumVersions as $name => $minimum) {
            $package = $packages->get($name);
            $this->assertIsArray($package, $name);
            $this->assertTrue(version_compare(ltrim((string) $package['version'], 'v'), $minimum, '>='),
                $name.' must include the vendor security fixes.');
        }
    }

    public function test_core_administrator_documentation_is_bilingual(): void
    {
        foreach ([
            'docs/en/README.md',
            'docs/en/INSTALLATION.md',
            'docs/en/SHARED_HOSTING.md',
            'docs/en/SECURITY.md',
            'docs/en/OPERATIONS.md',
            'docs/ru/README.md',
            'docs/ru/INSTALLATION.md',
            'docs/ru/SHARED_HOSTING.md',
            'docs/ru/SECURITY.md',
            'docs/ru/OPERATIONS.md',
        ] as $documentation) {
            $this->assertFileExists(base_path($documentation));
        }
    }

    public function test_update_script_uses_data_driven_release_and_recovery_contracts(): void
    {
        $release = $this->releaseContract();
        $update = $this->jsonReleaseFile('deployment/windows/update-contract.json');
        $deletions = $this->jsonReleaseFile('deployment/updates/deletions.json');

        $this->assertSame(1, $update['schema']);
        $this->assertSame([
            'preflight',
            'backup_obsolete',
            'maintenance',
            'dependencies',
            'cache_clear',
            'migrations',
            'queue_restart',
            'monitoring',
            'tests',
            'record_release',
            'cleanup',
        ], $update['stage_order']);
        $this->assertContains('.env', $update['protected_environment_files']);
        $this->assertContains('APP_KEY', $update['protected_environment_keys']);
        $this->assertContains('DB_PASSWORD', $update['protected_environment_keys']);
        $this->assertContains('MAIL_PASSWORD', $update['protected_environment_keys']);
        $this->assertContains('bootstrap/cache', $update['runtime_directories']);
        $this->assertContains('public/uploads/account-avatars', $update['runtime_directories']);
        $this->assertContains('public/uploads/game-assets/items/common', $update['runtime_directories']);
        $this->assertSame(
            $update['runtime_directories'],
            array_values(array_unique($update['runtime_directories'])),
        );

        $version = (string) $release['version'];
        $this->assertArrayHasKey($version, $deletions);
        $currentDeletions = $deletions[$version];
        $this->assertContains('core/'.(string) $release['previous_apply_script'], $currentDeletions);

        $accumulatedDeletions = [];
        foreach ($deletions as $deletionVersion => $paths) {
            if (version_compare((string) $deletionVersion, $version, '>')) {
                continue;
            }

            $accumulatedDeletions = array_merge($accumulatedDeletions, $paths);
        }
        $accumulatedDeletions = array_values(array_unique($accumulatedDeletions));

        $this->assertContains('public/assets/admin/css/app.css', $accumulatedDeletions);
        $this->assertContains('public/account-themes/luxury/assets/js/navigation.js', $accumulatedDeletions);
        $this->assertContains('public/account-themes/kaev-aurelia/assets/js/navigation.js', $accumulatedDeletions);
        $this->assertNotContains('public/assets/account', $accumulatedDeletions);

        $this->assertFileExists(base_path((string) $release['update_script']));
        $this->assertFileExists(base_path((string) $release['apply_script']));
        $this->assertFileExists(base_path('deployment/windows/tests/update-workflow.ps1'));
        $this->assertFileExists(base_path('deployment/windows/support/release-update-support.ps1'));

        $queueSql = $this->readReleaseFile('integrations/reward-queue/install.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `kaev_reward_queue`', $queueSql);
        $this->assertStringContainsString('`request_uuid` CHAR(36)', $queueSql);
        $this->assertStringContainsString('`item_id` BIGINT UNSIGNED', $queueSql);
        $this->assertStringContainsString('`amount` BIGINT UNSIGNED', $queueSql);

        $this->assertDirectoryDoesNotExist(base_path('integrations/mobius-interlude/reward-bridge'));
        $this->assertFileDoesNotExist(app_path('Jobs/ProcessRewardDelivery.php'));
        $this->assertFileDoesNotExist(app_path('Jobs/ConfirmRewardDelivery.php'));

        $phpunit = $this->readReleaseFile('phpunit.xml');
        $this->assertStringContainsString('<env name="APP_MAINTENANCE_DRIVER" value="cache" force="true"/>', $phpunit);
        $this->assertStringContainsString('<env name="APP_MAINTENANCE_STORE" value="array" force="true"/>', $phpunit);
        $this->assertStringNotContainsString('<env name="APP_MAINTENANCE_DRIVER" value="file"/>', $phpunit);

        $package = $this->jsonReleaseFile('package.json');
        $this->assertArrayHasKey('test:browser', $package['scripts']);
        $this->assertArrayHasKey('@playwright/test', $package['devDependencies']);

        $workflow = $this->readReleaseFile('.github/workflows/quality.yml');
        $this->assertStringContainsString('composer audit --locked --no-interaction', $workflow);
        $this->assertStringContainsString('npm audit --audit-level=high', $workflow);
    }

    public function test_reliable_game_account_creation_artifacts_are_shipped(): void
    {
        $this->assertFileExists(app_path('Console/Commands/RecoverGameAccountCreationCommand.php'));
        $this->assertFileExists(app_path('Services/GameAccounts/GameAccountProvisioner.php'));
        $this->assertFileExists(app_path('Support/GameAccounts/ExternalGameAccountState.php'));
        $this->assertFileExists(app_path('Support/GameAccounts/ExternalGameAccountWriteResult.php'));
        $this->assertFileExists(app_path('Support/GameAccounts/GameAccountCreationFailure.php'));
        $this->assertFileExists(database_path('migrations/2026_07_27_000000_add_creation_state_to_user_game_accounts.php'));
        $this->assertFileExists(base_path('tests/Feature/Account/GameAccountCreationReliabilityTest.php'));

        $command = $this->readReleaseFile('app/Console/Commands/RecoverGameAccountCreationCommand.php');
        $this->assertStringContainsString('kaevcms:game-accounts-recover', $command);
        $this->assertStringContainsString('{--retry', $command);
        $this->assertStringContainsString('{--older-than=300', $command);

        $russianRunbook = $this->readReleaseFile('docs/ru/OPERATIONS.md');
        $englishRunbook = $this->readReleaseFile('docs/en/OPERATIONS.md');
        foreach ([$russianRunbook, $englishRunbook] as $runbook) {
            $this->assertStringContainsString('kaevcms:game-accounts-recover', $runbook);
            $this->assertStringContainsString('--retry', $runbook);
        }

        $consoleRoutes = $this->readReleaseFile('routes/console.php');
        $this->assertStringNotContainsString('game-accounts-recover', $consoleRoutes);
    }

    public function test_module_foundation_release_artifacts_are_shipped(): void
    {
        $this->assertFileExists(app_path('Providers/ModuleServiceProvider.php'));
        $this->assertFileExists(app_path('Http/Middleware/EnsureModuleEnabled.php'));
        $this->assertFileExists(app_path('Models/ModuleMigration.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleManager.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleAutoloader.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleMigrationManager.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleRuntime.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleValidator.php'));
        $this->assertFileExists(database_path('migrations/2026_07_20_000200_create_cms_modules_table.php'));
        $this->assertFileExists(database_path('migrations/2026_07_21_000000_add_module_migration_lifecycle.php'));
        $this->assertFileExists(resource_path('schemas/module.schema.json'));
        $this->assertFileExists(resource_path('views/admin/modules/index.blade.php'));
        $this->assertFileExists(base_path('modules/README.md'));
        $this->assertFileExists(base_path('docs/en/MODULES.md'));
        $this->assertFileExists(base_path('docs/ru/MODULES.md'));

        $schema = json_decode(
            $this->readReleaseFile('resources/schemas/module.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['schema', 'id', 'name', 'version', 'author'], $schema['required']);
        $this->assertSame(1, $schema['properties']['schema']['const']);
        $this->assertSame('#/$defs/relativePath', $schema['properties']['migrations']['$ref']);
    }

    public function test_support_ticket_module_and_scoped_access_artifacts_are_shipped(): void
    {
        foreach ([
            'app/Support/Modules/ModuleAdminAccessDecision.php',
            'app/Support/Modules/ModuleAdminAccessLevel.php',
            'app/Support/Modules/ModuleAdminAccessRegistry.php',
            'app/Support/Modules/ModuleAdminAccessRule.php',
            'modules/support-tickets/module.json',
            'modules/support-tickets/bootstrap.php',
            'modules/support-tickets/assets/README.md',
            'modules/support-tickets/assets/module.webp',
            'modules/support-tickets/docs/README.ru.md',
            'modules/support-tickets/docs/README.en.md',
            'modules/support-tickets/database/migrations/2026_07_29_200000_create_module_support_ticket_settings_table.php',
            'modules/support-tickets/database/migrations/2026_07_29_200100_create_module_support_tickets_table.php',
            'modules/support-tickets/database/migrations/2026_07_29_200200_create_module_support_ticket_messages_table.php',
            'modules/support-tickets/database/migrations/2026_07_29_200300_create_module_support_ticket_message_revisions_table.php',
            'modules/support-tickets/database/migrations/2026_07_29_210000_harden_module_support_tickets.php',
            'modules/support-tickets/database/migrations/2026_07_29_220000_make_support_ticket_limits_configurable.php',
            'modules/support-tickets/resources/views/livewire/account-ticket-conversation.blade.php',
            'modules/support-tickets/resources/views/livewire/account-ticket-index.blade.php',
            'modules/support-tickets/resources/views/livewire/admin-ticket-conversation.blade.php',
            'modules/support-tickets/src/Console/Commands/CleanupSupportTicketsCommand.php',
            'modules/support-tickets/src/Livewire/AccountTicketConversation.php',
            'modules/support-tickets/src/Livewire/AccountTicketIndex.php',
            'modules/support-tickets/src/Livewire/AdminTicketConversation.php',
            'app/Support/Modules/AuthorizesModuleAdminAccess.php',
            'app/Support/Modules/ModuleAdminAuthorizer.php',
            'app/Support/Modules/ModuleAdminComponent.php',
            'app/Livewire/Admin/ModuleNavigationBadge.php',
            'modules/support-tickets/src/Services/SupportTicketCleanupService.php',
            'public/assets/modules/support-tickets.css',
            'public/assets/modules/support-tickets.js',
            'tests/Feature/Modules/SupportTicketsModuleTest.php',
            'tests/Unit/ModuleAdminAccessRegistryTest.php',
            'tests/Unit/ModuleAdminAuthorizerTest.php',
            'tests/browser/specs/support-tickets.spec.mjs',
            'tests/browser/support/authentication.mjs',
            'tests/browser/support/authentication.test.mjs',
        ] as $artifact) {
            $this->assertFileExists(base_path($artifact));
        }

        $service = $this->readReleaseFile('modules/support-tickets/src/Services/SupportTicketService.php');
        $this->assertStringNotContainsString("details: ['body'", $service);
        $this->assertStringNotContainsString("parameters: ['subject'", $service);
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
        $this->assertFileExists(base_path('docs/en/GAME_INTEGRATION.md'));
        $this->assertFileExists(base_path('docs/ru/GAME_INTEGRATION.md'));
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
        $this->assertFileDoesNotExist(base_path('docs/AUDIT-0.30.0.md'));
        $this->assertFileDoesNotExist(base_path('docs/AUDIT_0.29.0.md'));
        $this->assertDirectoryDoesNotExist(base_path('docs/history'));

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
        $this->assertDirectoryDoesNotExist(public_path('game-assets'));
        $webUpdateBuilder = $this->readReleaseFile('deployment/updates/build-package.php');
        $this->assertStringContainsString('\'public/uploads/\'', $webUpdateBuilder);
        $this->assertStringNotContainsString('public/game-assets', $webUpdateBuilder);

        $sharedHostingBuilder = $this->readReleaseFile('deployment/hosting/build-shared-hosting-package.php');
        $this->assertStringNotContainsString('str_starts_with(strtolower($path), \'game-assets/\')', $sharedHostingBuilder);
        $this->assertFileExists(app_path('Services/GameAssets/CharacterAppearanceResolver.php'));
        $this->assertFileExists(config_path('character-appearances.php'));
        $this->assertFileExists(resource_path('views/components/game-character-avatar.blade.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleNavigationRegistry.php'));
        $this->assertFileExists(app_path('Support/Modules/ModuleGameServerDependencyRegistry.php'));
        $this->assertFileExists(base_path('modules/promo-codes/module.json'));
        $this->assertFileExists(base_path('modules/promo-codes/bootstrap.php'));
        $this->assertFileExists(base_path('modules/promo-codes/database/migrations/2026_07_21_100000_create_module_promo_codes_table.php'));
        $this->assertFileExists(base_path('modules/promo-codes/database/migrations/2026_07_21_100100_create_module_promo_code_rewards_table.php'));
        $this->assertFileExists(base_path('modules/promo-codes/database/migrations/2026_07_21_100200_create_module_promo_code_activations_table.php'));
        $this->assertFileExists(base_path('modules/promo-codes/database/migrations/2026_07_21_100300_add_deleted_at_to_module_promo_codes_table.php'));
        $this->assertFileExists(base_path('modules/promo-codes/src/Services/PromoCodeActivationService.php'));
        $this->assertFileDoesNotExist(base_path('docs/GAME_ASSETS.md'));
        $this->assertFileDoesNotExist(base_path('docs/CHARACTER_AVATARS.md'));
        $this->assertFileExists(public_path('uploads/game-assets/items/common/.gitkeep'));
        $this->assertFileExists(public_path('uploads/game-assets/items/servers/.gitkeep'));
        $this->assertFileExists(public_path('uploads/game-assets/characters/common/human/female/.gitkeep'));
        $this->assertFileExists(public_path('uploads/game-assets/characters/servers/.gitkeep'));
        $uploadsIgnore = $this->readReleaseFile('public/uploads/.gitignore');
        $this->assertStringContainsString('!game-assets/**/.gitkeep', $uploadsIgnore);
        $this->assertFileExists(public_path('assets/admin/js/promo-codes.js'));
        foreach (['base', 'layout', 'content', 'infrastructure', 'components', 'extensions', 'catalogs', 'appearance'] as $stylesheet) {
            $this->assertFileExists(public_path('assets/admin/css/'.$stylesheet.'.css'));
        }
        $this->assertFileDoesNotExist(public_path('assets/admin/css/app.css'));
        $this->assertFileExists(public_path('assets/account/css/components.css'));
        $this->assertFileExists(public_path('assets/account/js/navigation.js'));
        $this->assertFileDoesNotExist(public_path('account-themes/luxury/assets/js/navigation.js'));
        $this->assertFileDoesNotExist(public_path('account-themes/kaev-aurelia/assets/js/navigation.js'));
        $this->assertFileExists(base_path('modules/promo-codes/assets/module.webp'));
        $this->assertFileExists(base_path('modules/daily-rewards/assets/module.webp'));
        $this->assertFileExists(resource_path('views/components/account-operation-modal.blade.php'));
        $dailyRewardsScript = $this->readReleaseFile('public/assets/admin/js/daily-rewards.js');
        $this->assertStringContainsString('data-daily-unsaved', $this->readReleaseFile('modules/daily-rewards/resources/views/admin/edit.blade.php'));
        $this->assertStringContainsString('beforeunload', $dailyRewardsScript);
        $this->assertStringContainsString('copyEmptyConfirm', $dailyRewardsScript);
        $this->assertStringContainsString('addEventListener(\'pointerdown\'', $dailyRewardsScript);
        $activationService = $this->readReleaseFile('modules/promo-codes/src/Services/PromoCodeActivationService.php');
        $this->assertStringContainsString('lockForUpdate()', $activationService);
        $this->assertStringContainsString('grantKey: \'promo-code.activation.\'.$activation->id', $activationService);
        $this->assertStringContainsString('RewardInventoryService', $activationService);
        $this->assertStringNotContainsString('table(\'items\')', $activationService);

        $moduleValidator = $this->readReleaseFile('app/Support/Modules/ModuleValidator.php');
        $this->assertStringContainsString('\'assets/module.webp\'', $moduleValidator);
        $this->assertStringContainsString('$image[\'mime\'] !== \'image/webp\'', $moduleValidator);
        $moduleController = $this->readReleaseFile('app/Http/Controllers/Admin/ModuleController.php');
        $this->assertStringContainsString('\'X-Content-Type-Options\' => \'nosniff\'', $moduleController);

        $interludeCatalog = json_decode(
            $this->readReleaseFile('resources/game-items/interlude.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
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
        $this->assertSame('etc_adena_i00', $classicCatalog[57]['icon']);
        $this->assertSame('etc_adena_i00', $highFiveCatalog[57]['icon']);
        $this->assertSame('etc_adena_i00', $shineMakerCatalog[57]['icon']);
    }

    public function test_external_database_diagnostics_artifacts_are_shipped(): void
    {
        $this->assertFileExists(app_path('Services/Servers/ExternalDatabaseDiagnostics.php'));
        $this->assertFileExists(app_path('Services/Servers/ExternalDatabaseInformation.php'));
        $this->assertFileExists(app_path('Exceptions/ExternalDatabaseDriverUnavailable.php'));
        $this->assertFileExists(app_path('Exceptions/ExternalDatabaseSchemaMismatch.php'));
        $this->assertFileExists(database_path('migrations/2026_07_27_200000_add_external_database_diagnostics_to_servers.php'));
        $this->assertFileExists(resource_path('views/admin/settings/_external_database_diagnostics.blade.php'));
        $this->assertFileExists(lang_path('en/external_databases.php'));
        $this->assertFileExists(lang_path('ru/external_databases.php'));
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
        $this->assertStringContainsString('public/uploads/account-avatars', $this->readReleaseFile('docs/en/CONTENT_THEMES.md'));
        $this->assertStringContainsString('public/uploads/account-avatars', $this->readReleaseFile('docs/ru/CONTENT_THEMES.md'));

        $updateContract = $this->jsonReleaseFile('deployment/windows/update-contract.json');
        $this->assertContains('public/uploads/account-avatars', $updateContract['runtime_directories']);
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

    /** @return array<string, mixed> */
    private function releaseContract(): array
    {
        return $this->jsonReleaseFile('release.json');
    }

    /** @return array{schema: int, required_files: list<string>} */
    private function releaseFileManifest(): array
    {
        /** @var array{schema: int, required_files: list<string>} $manifest */
        $manifest = $this->jsonReleaseFile('deployment/release-files.json');

        return $manifest;
    }

    /** @return array<string, mixed> */
    private function jsonReleaseFile(string $path): array
    {
        $decoded = json_decode($this->readReleaseFile($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function normalized(string $contents): string
    {
        return str_replace("\r\n", "\n", $contents);
    }
}
