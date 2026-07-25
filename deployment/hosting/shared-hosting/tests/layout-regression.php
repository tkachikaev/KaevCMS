<?php

declare(strict_types=1);

define('KAEVCMS_INSTALLER_FUNCTIONS_ONLY', true);
require dirname(__DIR__, 2).'/web-installer/installer.php';

function assertSharedLayout(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$hostingRoot = dirname(__DIR__, 2);
$projectRoot = dirname($hostingRoot, 2);

$unsafe = installerDeploymentSafety('/public/install/index.php', false);
$unsafeDirectory = installerDeploymentSafety('/public/install/', false);
assertSharedLayout($unsafe['ok'] === false, 'The installer must reject a project root exposed above /public/.');
assertSharedLayout($unsafeDirectory['ok'] === false, 'The installer must reject the directory-style /public/install/ URL.');

$standard = installerDeploymentSafety('/install/index.php', false);
assertSharedLayout($standard['ok'] === true, 'The standard public Document Root layout must remain supported.');

$split = installerDeploymentSafety('/install/index.php', true);
assertSharedLayout($split['ok'] === true, 'The split shared-hosting layout must be supported.');

foreach ([
    'build-shared-hosting-package.php',
    'shared-hosting/public/index.php',
    'shared-hosting/public/install/index.php',
    'shared-hosting/public/.htaccess',
    'shared-hosting/public/kaevcms-path.php.template',
    'shared-hosting/tests/update-entrypoint-regression.php',
] as $relative) {
    assertSharedLayout(is_file($hostingRoot.'/'.$relative), 'Missing shared-hosting file: '.$relative);
}

$bootstrap = file_get_contents($projectRoot.'/bootstrap/app.php');
$builder = file_get_contents($hostingRoot.'/build-shared-hosting-package.php');
$publicEntry = file_get_contents($hostingRoot.'/shared-hosting/public/index.php');
$installEntry = file_get_contents($hostingRoot.'/shared-hosting/public/install/index.php');
$standardPublicEntry = file_get_contents($projectRoot.'/public/index.php');
$standardInstallEntry = file_get_contents($projectRoot.'/public/install/index.php');
$standardHtaccess = file_get_contents($projectRoot.'/public/.htaccess');
$sharedHtaccess = file_get_contents($hostingRoot.'/shared-hosting/public/.htaccess');

assertSharedLayout(is_string($bootstrap) && str_contains($bootstrap, 'bootstrap/kaevcms-public-path.php') === false, 'Bootstrap override must be referenced by its local filename, not a fixed root path.');
assertSharedLayout(is_string($bootstrap) && str_contains($bootstrap, '__DIR__.\'/kaevcms-public-path.php\''), 'Laravel bootstrap must support the generated public-path override.');
assertSharedLayout(is_string($builder) && str_contains($builder, '\'public\', \'storage\', \'tests\''), 'The builder must keep the application core outside the public package and exclude tests.');
assertSharedLayout(is_string($builder) && str_contains($builder, 'vendor/autoload.php is missing'), 'The builder must refuse packages without Composer dependencies.');
assertSharedLayout(is_string($builder) && str_contains($builder, 'kaevcms-path.php.template'), 'The builder must generate the public core path from the shipped template.');
assertSharedLayout(is_string($builder) && str_contains($builder, 'Symbolic links are not allowed'), 'The builder must reject symbolic links.');
assertSharedLayout(is_string($publicEntry) && str_contains($publicEntry, '$pathFile = __DIR__.\'/kaevcms-path.php\''), 'The unified web entry must detect split hosting from the runtime locator.');
assertSharedLayout(is_string($publicEntry) && str_contains($publicEntry, '$application->usePublicPath(__DIR__)'), 'The shared web entry must bind Laravel to the real public directory.');
assertSharedLayout(is_string($installEntry) && str_contains($installEntry, 'define(\'KAEVCMS_SHARED_HOSTING\', true)'), 'The shared installer entry must declare the split layout.');
assertSharedLayout($standardPublicEntry === $publicEntry, 'Standard and shared-hosting web entries must remain update-compatible.');
assertSharedLayout($standardInstallEntry === $installEntry, 'Standard and shared-hosting installer entries must remain update-compatible.');
assertSharedLayout($standardHtaccess === $sharedHtaccess, 'Standard and shared-hosting Apache rules must remain update-compatible.');

$directInstaller = file_get_contents($hostingRoot.'/web-installer/installer.php');
assertSharedLayout(is_string($directInstaller) && str_contains($directInstaller, 'defined(\'KAEVCMS_INSTALL_ENTRY\')'), 'Direct execution of the internal installer must be blocked.');

fwrite(STDOUT, "Shared-hosting layout regression checks passed.\n");
