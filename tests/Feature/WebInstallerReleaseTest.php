<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebInstallerReleaseTest extends TestCase
{
    public function test_web_installer_and_safe_hosting_layouts_are_shipped(): void
    {
        foreach ([
            'public/install/index.php',
            'public/.htaccess',
            'deployment/hosting/README.md',
            'deployment/hosting/web-installer/installer.php',
            'deployment/hosting/web-installer/tests/installer-regression.php',
            'deployment/hosting/build-shared-hosting-package.php',
            'deployment/hosting/archive-shared-hosting-package.php',
            'deployment/hosting/shared-hosting/README.md',
            'deployment/hosting/shared-hosting/public/index.php',
            'deployment/hosting/shared-hosting/public/install/index.php',
            'deployment/hosting/shared-hosting/public/.htaccess',
            'deployment/hosting/shared-hosting/public/kaevcms-path.php.template',
            'deployment/hosting/shared-hosting/tests/layout-regression.php',
            'deployment/hosting/shared-hosting/tests/package-builder-regression.php',
        ] as $relative) {
            $this->assertFileExists(base_path($relative));
        }

        $entry = file_get_contents(public_path('index.php'));
        $installEntry = file_get_contents(public_path('install/index.php'));
        $installer = file_get_contents(base_path('deployment/hosting/web-installer/installer.php'));
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $builder = file_get_contents(base_path('deployment/hosting/build-shared-hosting-package.php'));
        $splitEntry = file_get_contents(base_path('deployment/hosting/shared-hosting/public/index.php'));

        $this->assertNotFalse($entry);
        $this->assertNotFalse($installEntry);
        $this->assertNotFalse($installer);
        $this->assertNotFalse($bootstrap);
        $this->assertNotFalse($builder);
        $this->assertNotFalse($splitEntry);

        $this->assertStringContainsString('if (! is_file($projectRoot.\'/.env\'))', $entry);
        $this->assertStringContainsString('header(\'Location: \'.$basePath.\'/install/\'', $entry);
        $this->assertStringContainsString('define(\'KAEVCMS_INSTALL_ENTRY\', true)', $installEntry);
        $this->assertStringContainsString('defined(\'KAEVCMS_INSTALL_ENTRY\')', $installer);
        $this->assertStringContainsString('installerDeploymentSafety', $installer);
        $this->assertStringContainsString('domain points to the project root', $installer);
        $this->assertStringContainsString('storage/app/installed.lock', $installer);
        $this->assertStringContainsString('session_set_cookie_params([', $installer);
        $this->assertStringContainsString('\'httponly\' => true', $installer);
        $this->assertStringContainsString('\'samesite\' => \'Lax\'', $installer);
        $this->assertStringContainsString('Content-Security-Policy:', $installer);
        $this->assertStringContainsString('header_remove(\'X-Powered-By\')', $installer);
        $this->assertStringContainsString('LOCK_EX | LOCK_NB', $installer);
        $this->assertStringContainsString('CREATE TABLE {$quoted}', $installer);
        $this->assertStringContainsString('DROP TABLE {$quoted}', $installer);
        $this->assertStringContainsString('buildEnvironmentContent', $installer);
        $this->assertStringContainsString('publicInstallerError', $installer);
        $this->assertStringContainsString('postInstallSecurityChecks', $installer);
        $this->assertStringContainsString('securityReviewBody', $installer);
        $this->assertStringContainsString('installerSensitivePermissionsAreBroad', $installer);
        $this->assertStringContainsString('Installation data is transmitted without encryption.', $installer);
        $this->assertStringContainsString('Данные установки передаются по сети без шифрования.', $installer);
        $this->assertStringContainsString("'public/uploads/news' => \$publicRoot.'/uploads/news'", $installer);
        $this->assertStringContainsString("'public/uploads/pages' => \$publicRoot.'/uploads/pages'", $installer);
        $this->assertStringContainsString("'public/uploads/settings' => \$publicRoot.'/uploads/settings'", $installer);
        $this->assertStringNotContainsString('requireSecureInstallerSubmission', $installer);
        $this->assertStringNotContainsString('https_required', $installer);
        $this->assertStringContainsString('hash_equals($expected, $provided)', $installer);
        $this->assertStringContainsString('PDO::ATTR_EMULATE_PREPARES => false', $installer);
        $this->assertStringContainsString('field($text[\'db_password\'], \'db_password\', \'\', \'password\'', $installer);
        $this->assertStringNotContainsString('field($text[\'db_password\'], \'db_password\', $db[\'password\']', $installer);
        $this->assertStringContainsString('callArtisanOrFail(\'migrate\'', $installer);
        $this->assertStringContainsString('assertNoExistingAdministrators(existingAdministratorCount($pdo))', $installer);
        $this->assertStringContainsString('DB::transaction(function () use ($administrator, $language): Admin', $installer);
        $this->assertStringContainsString('assertNoExistingAdministrators(Admin::query()->lockForUpdate()->get([\'id\'])->count())', $installer);
        $this->assertStringContainsString('Hash::make($administrator[\'password\'])', $installer);
        $this->assertStringContainsString('Hash::check($administrator[\'password\'], $created->password)', $installer);
        $this->assertStringNotContainsString('$owner = Admin::query()->where(\'role\', AdminRole::Owner->value)', $installer);
        $this->assertStringNotContainsString('shell_exec(', $installer);
        $this->assertStringNotContainsString('passthru(', $installer);
        $this->assertStringNotContainsString('proc_open(', $installer);
        $this->assertStringNotContainsString('system(', $installer);

        $this->assertStringContainsString('__DIR__.\'/kaevcms-public-path.php\'', $bootstrap);
        $this->assertStringContainsString('$application->usePublicPath($configuredPublicPath)', $bootstrap);
        $this->assertStringContainsString('vendor/autoload.php is missing', $builder);
        $this->assertStringContainsString('\'public\', \'storage\', \'tests\'', $builder);
        $this->assertStringContainsString('Symbolic links are not allowed', $builder);
        $this->assertStringContainsString('$application->usePublicPath(__DIR__)', $splitEntry);
    }

    public function test_windows_scripts_are_kept_in_one_deployment_directory(): void
    {
        foreach ([
            'setup.ps1',
            'serve.ps1',
            'doctor.ps1',
            'quality.ps1',
            'browser-setup.ps1',
            'browser-quality.ps1',
            'security-audit.ps1',
            'build-shared-hosting-package.ps1',
            'build-web-update-package.ps1',
            'update.ps1',
            'apply-'.cms_version().'.ps1',
        ] as $script) {
            $this->assertFileExists(base_path('deployment/windows/'.$script));
            $this->assertFileDoesNotExist(base_path($script));
        }

        $quality = file_get_contents(base_path('deployment/windows/quality.ps1'));
        $packageBuilder = file_get_contents(base_path('deployment/windows/build-shared-hosting-package.ps1'));
        $this->assertNotFalse($quality);
        $this->assertNotFalse($packageBuilder);
        $this->assertStringContainsString('Join-Path $PSScriptRoot \'..\\..\'', $quality);
        $this->assertStringContainsString('tests\\update-workflow.ps1', $quality);
        $this->assertStringContainsString('Initialize-KaevCmsRuntimeDirectories -ProjectRoot $ProjectRoot', $quality);
        $this->assertStringContainsString('deployment/hosting/web-installer/tests/installer-regression.php', $quality);
        $this->assertStringContainsString('deployment/hosting/shared-hosting/tests/layout-regression.php', $quality);
        $this->assertStringContainsString('deployment/hosting/shared-hosting/tests/package-builder-regression.php', $quality);
        $this->assertStringContainsString('[System.IO.Compression.ZipFile]::OpenRead($zipPath)', $packageBuilder);
        $this->assertStringContainsString('$entryName -match \'\\\\\'', $packageBuilder);
        $this->assertStringContainsString('Get-FileHash', $packageBuilder);
        $this->assertStringContainsString('--no-zip', $packageBuilder);
        $this->assertStringContainsString('archive-shared-hosting-package.php', $packageBuilder);
        $this->assertStringContainsString("'--no-dev'", $packageBuilder);
        $this->assertStringNotContainsString('CreateFromDirectory', $packageBuilder);
    }
}
