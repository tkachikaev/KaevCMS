<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WebUpdaterReleaseTest extends TestCase
{
    public function test_release_contains_the_manual_web_updater_foundation(): void
    {
        foreach ([
            app_path('Http/Controllers/Admin/SystemUpdateController.php'),
            app_path('Services/Updates/UpdatePackageInspector.php'),
            app_path('Services/Updates/SystemUpdateInstaller.php'),
            app_path('Services/Updates/SystemUpdateRecovery.php'),
            app_path('Services/Updates/UpdateLock.php'),
            app_path('Services/Updates/UpdateDatabaseBackup.php'),
            app_path('Services/Infrastructure/RuntimeDirectoryManager.php'),
            app_path('Console/Commands/EnsureRuntimeDirectoriesCommand.php'),
            base_path('tests/Unit/Updates/UpdateDatabaseBackupTest.php'),
            base_path('tests/Unit/Updates/UpdateFilesystemTransactionTest.php'),
            base_path('tests/Unit/Updates/SystemUpdatePhaseTest.php'),
            resource_path('views/admin/settings/updates/index.blade.php'),
            resource_path('views/admin/settings/updates/show.blade.php'),
            database_path('migrations/2026_07_23_000000_create_system_updates_table.php'),
            database_path('migrations/2026_07_23_010000_add_execution_state_to_system_updates_table.php'),
            base_path('deployment/updates/build-package.php'),
            base_path('deployment/updates/README.md'),
            base_path('deployment/hosting/shared-hosting/tests/update-entrypoint-regression.php'),
        ] as $path) {
            $this->assertFileExists($path);
        }

        $routes = File::get(base_path('routes/admin.php'));
        $this->assertStringContainsString('/settings/system/updates', $routes);
        $this->assertStringContainsString('settings.system.updates.apply', $routes);
        $this->assertStringContainsString('settings.system.updates.recover', $routes);

        $builder = File::get(base_path('deployment/updates/build-package.php'));
        foreach ([
            "'.env.example'",
            "'public/uploads/.gitignore'",
            "'public/uploads/.htaccess'",
            "'public/install/'",
            "\$delete[] = 'public/install';",
            "'public/kaevcms-path.php'",
            'legacyWebUpdaterAcceptsTarget',
            'oldest supported Web Updater',
        ] as $required) {
            $this->assertStringContainsString($required, $builder);
        }

        $inspector = File::get(app_path('Services/Updates/UpdatePackageInspector.php'));
        foreach ([
            'kaevcms-update.json',
            'Symbolic links are not allowed',
            'core/VERSION',
            'hash_equals',
            'minimum_version',
            'maximum_version',
            'Web Updater 1.0 requires a full deployment',
        ] as $required) {
            $this->assertStringContainsString($required, $inspector);
        }

        $installer = File::get(app_path('Services/Updates/SystemUpdateInstaller.php'));
        foreach ([
            '\'--secret\' => $maintenanceSecret',
            'runArtisan(\'down\'',
            'runArtisan(\'migrate\'',
            'runArtisan(\'optimize:clear\'',
            'runtimeDirectories->ensure(true)',
            'Runtime directories recreated and write-tested after cache clear.',
            'databaseBackup->create',
            'filesystem->backup',
            'databaseBackup->restore',
            'filesystem->rollback',
            'installedVersion->mark',
            'updateLock->acquire',
            'PHASE_PREPARING',
            'PHASE_FILES',
            'PHASE_MIGRATIONS',
            'PHASE_FINALIZING',
            'package_sha256',
        ] as $required) {
            $this->assertStringContainsString($required, $installer);
        }

        $publicEntry = File::get(public_path('index.php'));
        $sharedPublicEntry = File::get(base_path('deployment/hosting/shared-hosting/public/index.php'));
        $publicInstallEntry = File::get(public_path('install/index.php'));
        $sharedInstallEntry = File::get(base_path('deployment/hosting/shared-hosting/public/install/index.php'));
        $this->assertSame($publicEntry, $sharedPublicEntry);
        $this->assertSame($publicInstallEntry, $sharedInstallEntry);
        $this->assertSame(
            File::get(public_path('.htaccess')),
            File::get(base_path('deployment/hosting/shared-hosting/public/.htaccess')),
        );
        $this->assertStringContainsString('$pathFile = __DIR__.\'/kaevcms-path.php\'', $publicEntry);
        $this->assertStringContainsString('$sharedHosting = is_file($pathFile)', $publicEntry);
        $this->assertStringContainsString('RewriteRule ^kaevcms-path\.php$ - [F,L]', File::get(public_path('.htaccess')));

        $filesystem = File::get(app_path('Services/Updates/UpdateFilesystemTransaction.php'));
        foreach ([
            'pathDigest',
            'hash_equals',
            '\'sha256\' => $this->pathDigest',
            'loadBackup',
        ] as $required) {
            $this->assertStringContainsString($required, $filesystem);
        }

        $recovery = File::get(app_path('Services/Updates/SystemUpdateRecovery.php'));
        foreach ([
            'databaseBackup->load',
            'filesystem->loadBackup',
            'STATUS_APPLYING',
            'runArtisan(\'up\'',
            'runtimeDirectories->ensure(true)',
            'Runtime directories recreated and write-tested after recovery cache clear.',
            'filesMayHaveChanged',
            'databaseMayHaveChanged',
        ] as $required) {
            $this->assertStringContainsString($required, $recovery);
        }
    }
}
