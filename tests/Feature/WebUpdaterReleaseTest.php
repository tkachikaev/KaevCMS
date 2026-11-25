<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebUpdaterReleaseTest extends TestCase
{
    public function test_update_and_mail_probe_actions_do_not_use_shared_generic_throttles(): void
    {
        foreach ([
            'admin.settings.system.updates.store',
            'admin.settings.system.updates.chunk',
            'admin.settings.system.updates.import',
            'admin.settings.system.updates.apply',
            'admin.settings.system.updates.recover',
            'admin.settings.mail.delivery-probe',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            if ($route === null) {
                $this->fail($routeName.' route is missing.');
            }

            $this->assertSame([], array_values(array_filter(
                $route->gatherMiddleware(),
                static fn (string $middleware): bool => str_starts_with($middleware, 'throttle:'),
            )), $routeName.' must rely on operation state instead of a shared HTTP 429 limiter.');
        }
    }

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
            database_path('migrations/2026_08_01_100000_add_vds_agent_state_to_system_updates.php'),
            app_path('Services/Updates/VdsUpdateAgent.php'),
            app_path('Services/Updates/VdsUpdateAgentStatus.php'),
            app_path('Console/Commands/RegisterVdsUpdateAgentCommand.php'),
            app_path('Console/Commands/RunVdsUpdateAgentCommand.php'),
            app_path('Console/Commands/VdsUpdateAgentStatusCommand.php'),
            public_path('assets/admin/js/system-updates.js'),
            base_path('deployment/vds/install-update-agent.sh'),
            base_path('deployment/vds/remove-update-agent.sh'),
            base_path('tests/Feature/Updates/VdsUpdateAgentTest.php'),
            base_path('tests/browser/specs/system-updates.spec.mjs'),
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
        $this->assertStringContainsString('settings.system.updates.status', $routes);

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

        $installerScript = File::get(base_path('deployment/vds/install-update-agent.sh'));
        foreach ([
            'DirectoryNotEmpty=',
            'Type=oneshot',
            'NoNewPrivileges=true',
            'ProtectSystem=full',
            'kaevcms:update-agent:register',
            'kaevcms:update-agent:run',
            'UPDATE_ROOT=',
            'PACKAGE_DIR=',
            'STAGING_DIR=',
            '-m 2770',
            '--project-user',
            '--web-group',
            '--web-user',
            'requesting sudo',
            'php-fpm: pool',
            'repair_kaevcms_runtime',
            'UMask=0027',
            '--deployment-user=',
            '--web-gid=',
        ] as $required) {
            $this->assertStringContainsString($required, $installerScript);
        }
        $this->assertStringNotContainsString('ListenStream=', $installerScript);
        $this->assertStringNotContainsString('the project is owned by root. Set the correct owner', $installerScript);
        $this->assertStringContainsString('the KaevCMS update agent will run as root', $installerScript);
        $this->assertStringContainsString('An applied package receives root-level access', $installerScript);
        $this->assertStringContainsString('KAEVCMS_WEB_GROUP', $installerScript);
        $this->assertStringContainsString('id www-data', $installerScript);
        $this->assertStringContainsString('Web upload directories:', $installerScript);
        $this->assertStringContainsString('find "${public_root}" -mindepth 1', $installerScript);
        $this->assertStringContainsString('find "${root}" -mindepth 1 -path "${root}/update-backups"', $installerScript);
        $this->assertStringNotContainsString('-prune -o \\
        -mindepth 1', $installerScript);
        $this->assertStringNotContainsString('-prune -o -mindepth 1', $installerScript);
        $this->assertStringContainsString('secureUpdatedTarget', File::get(app_path('Services/Updates/UpdateFilesystemTransaction.php')));

        $cliUpdater = File::get(app_path('Console/Commands/InstallSystemUpdateCommand.php'));
        $this->assertStringContainsString('prepareSharedDirectory', $cliUpdater);
        $this->assertStringContainsString('chmod($path, 02770)', $cliUpdater);
        $this->assertStringContainsString('chmod($path, 0660)', $cliUpdater);
        $this->assertStringNotContainsString('chmod($path, 0700)', $cliUpdater);

        $request = File::get(app_path('Http/Requests/Admin/ApplySystemUpdateRequest.php'));
        $this->assertStringContainsString("'trusted_source' => ['accepted']", $request);
    }
}
