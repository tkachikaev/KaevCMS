<?php

declare(strict_types=1);

define('KAEVCMS_INSTALLER_FUNCTIONS_ONLY', true);
require dirname(__DIR__).'/installer.php';

function assertInstaller(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$temp = sys_get_temp_dir().'/kaevcms-installer-'.bin2hex(random_bytes(6));
mkdir($temp, 0700, true);

try {
    $example = $temp.'/.env.example';
    $active = $temp.'/.env';
    file_put_contents($example, 'APP_NAME=KaevCMS'.PHP_EOL.'APP_KEY='.PHP_EOL.'DB_PASSWORD='.PHP_EOL);
    file_put_contents($active, "APP_NAME=Old\nAPP_KEY=\"base64:KEEP_EXISTING_KEY\"\nDB_PASSWORD=\"old\"\n");

    $specialName = 'My "L2" # Server';
    $specialPassword = "pa# ss\\word\"dollar\$line\nnext";
    $content = buildEnvironmentContent($example, $active, [
        'APP_NAME' => $specialName,
        'DB_PASSWORD' => $specialPassword,
    ]);
    file_put_contents($active, $content);
    $parsed = parseSimpleEnv($active);

    assertInstaller(($parsed['APP_KEY'] ?? null) === 'base64:KEEP_EXISTING_KEY', 'A resumed installation must preserve APP_KEY.');
    assertInstaller(($parsed['APP_NAME'] ?? null) === $specialName, 'Quoted site names must survive .env encoding.');
    assertInstaller(($parsed['DB_PASSWORD'] ?? null) === $specialPassword, 'Database passwords with spaces, #, quotes, slashes, dollars, and newlines must survive .env encoding.');
    assertInstaller(str_starts_with(envEncode('plain'), '"'), 'Environment values must always be quoted.');

    $atomic = $temp.'/atomic.txt';
    file_put_contents($atomic, 'old');
    writeFileAtomically($atomic, 'new', 0600);
    assertInstaller(file_get_contents($atomic) === 'new', 'Atomic writes must safely replace an existing file.');

    $_SERVER['HTTPS'] = 'on';
    $text = installerTranslations('en');
    $secret = 'NeverRenderThisPassword!';
    $html = databaseBody($text, [
        'csrf' => 'token',
        'database' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'kaevcms',
            'username' => 'kaevcms',
            'password' => $secret,
        ],
        'site' => ['url' => 'https://example.test', 'name' => 'KaevCMS'],
    ]);
    assertInstaller(! str_contains($html, $secret), 'The verified database password must never be rendered into HTML.');
    assertInstaller(str_contains($html, 'name="db_password" value=""'), 'The database password input must be blank after verification.');
    assertInstaller(! str_contains($html, 'name="db_password" value="" required'), 'A blank field must be allowed when the server-side session already has the password.');

    $validationFailed = false;
    try {
        validateDatabaseInput([
            'db_host' => '127.0.0.1;unix_socket=/tmp/mysql.sock',
            'db_port' => '3306',
            'db_database' => 'kaevcms',
            'db_username' => 'user',
            'db_password' => 'secret',
        ], $text);
    } catch (InstallerValidationException) {
        $validationFailed = true;
    }
    assertInstaller($validationFailed, 'Unsafe DSN host fragments must be rejected.');

    assertNoExistingAdministrators(0);
    $existingInstallationBlocked = false;
    try {
        assertNoExistingAdministrators(1);
    } catch (InstallerExistingInstallationException) {
        $existingInstallationBlocked = true;
    }
    assertInstaller($existingInstallationBlocked, 'An existing administrator must block a fresh installation instead of silently reusing its password.');

    $existingInstallationMessage = publicInstallerError(
        new InstallerExistingInstallationException('internal'),
        $text,
        'ABC12345',
    );
    assertInstaller(
        $existingInstallationMessage === $text['database_existing_installation'],
        'An existing installation must produce a stable localized message.',
    );

    $lockPath = $temp.'/installing.lock';
    $firstLock = acquireInstallationLock($lockPath, 'first-token');
    $busyDetected = false;
    try {
        acquireInstallationLock($lockPath, 'second-token');
    } catch (InstallerBusyException) {
        $busyDetected = true;
    }
    releaseInstallationLock($firstLock);
    assertInstaller($busyDetected, 'A concurrent installer process must not acquire the same lock.');
    assertInstaller(installationLockOwnedByToken($lockPath, 'first-token'), 'The interrupted installer state must remain bound to its original browser token.');
    assertInstaller(! installationLockOwnedByToken($lockPath, 'second-token'), 'A different browser token must not resume an interrupted installation.');

    $serverBackup = $_SERVER;
    $_SERVER = [
        'HTTPS' => 'off',
        'SERVER_PORT' => '80',
        'HTTP_HOST' => 'example.test',
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ];
    assertInstaller(! isHttpsRequest(), 'A public client must not be able to spoof HTTPS with X-Forwarded-Proto.');
    $publicHttpDatabase = databaseBody($text, [
        'csrf' => 'token',
        'database' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => '', 'username' => '', 'password' => ''],
        'site' => ['url' => 'http://example.test', 'name' => 'KaevCMS'],
    ]);
    assertInstaller(str_contains($publicHttpDatabase, $text['https_warning']), 'HTTP installation must show the generic transport warning.');
    assertInstaller(str_contains($publicHttpDatabase, 'value="continue_database"'), 'HTTP installation must keep the database continuation action available.');

    $_SERVER = [
        'HTTPS' => 'off',
        'SERVER_PORT' => '80',
        'HTTP_HOST' => '192.168.50.111',
        'REMOTE_ADDR' => '192.168.50.10',
    ];
    $privateHttpDatabase = databaseBody($text, [
        'csrf' => 'token',
        'database' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => '', 'username' => '', 'password' => ''],
        'site' => ['url' => 'http://192.168.50.111', 'name' => 'KaevCMS'],
    ]);
    assertInstaller(str_contains($privateHttpDatabase, $text['https_warning']), 'Private HTTP installation must use the same generic transport warning.');
    assertInstaller(str_contains(administratorBody($text, ['csrf' => 'token']), 'value="install"'), 'HTTP installation must keep the final install action available.');

    $_SERVER = [
        'HTTPS' => 'off',
        'SERVER_PORT' => '80',
        'HTTP_HOST' => 'example.test',
        'REMOTE_ADDR' => '10.0.0.10',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ];
    assertInstaller(isHttpsRequest(), 'A private reverse proxy may report HTTPS through X-Forwarded-Proto.');

    $installerSource = file_get_contents(dirname(__DIR__).'/installer.php');
    assertInstaller(is_string($installerSource), 'The installer source must be readable.');
    assertInstaller(! str_contains($installerSource, 'requireSecureInstallerSubmission'), 'HTTP password submission must not be blocked by a transport gate.');
    assertInstaller(! str_contains($installerSource, 'https_required'), 'The obsolete blocking HTTPS validation message must be removed.');
    assertInstaller(str_contains($installerSource, "callArtisanOrFail('kaevcms:runtime-directories', ['--probe' => true])"), 'The installer must recreate and write-test runtime directories after clearing caches.');
    assertInstaller(str_contains($installerSource, '$probeDirectory = $probeRoot.\'/nested\''), 'The installer write check must verify nested directory creation.');

    $rateState = [];
    enforceInstallerRateLimit($rateState, 'database', 2, 60, 'limited');
    enforceInstallerRateLimit($rateState, 'database', 2, 60, 'limited');
    $rateLimited = false;
    try {
        enforceInstallerRateLimit($rateState, 'database', 2, 60, 'limited');
    } catch (InstallerValidationException) {
        $rateLimited = true;
    }
    assertInstaller($rateLimited, 'Repeated database probes must be rate limited within the installer session.');
    $_SERVER = $serverBackup;

    $unsafeLayout = installerDeploymentSafety('/public/install/index.php', false);
    $unsafeDirectoryLayout = installerDeploymentSafety('/public/install/', false);
    assertInstaller($unsafeLayout['ok'] === false, 'The installer must block an exposed project root above /public/.');
    assertInstaller($unsafeDirectoryLayout['ok'] === false, 'The installer must also recognize the directory-style /public/install/ URL.');
    $standardLayout = installerDeploymentSafety('/install/index.php', false);
    assertInstaller($standardLayout['ok'] === true, 'The standard public Document Root layout must remain valid.');
    $splitLayout = installerDeploymentSafety('/install/index.php', true);
    assertInstaller($splitLayout['ok'] === true, 'The shared-hosting split layout must remain valid.');

    assertInstaller(hasFailedRequirements([['label' => 'HTTPS', 'ok' => true, 'warning' => true, 'details' => 'warning']]) === false, 'Non-critical hosting warnings must not block installation.');

    $publicRoot = $temp.'/public';
    mkdir($publicRoot, 0775, true);
    $uploadChecks = requirementChecks($temp, $publicRoot, $text);
    $uploadCheckMap = [];
    foreach ($uploadChecks as $check) {
        $uploadCheckMap[$check['label']] = $check;
    }
    foreach ([
        'public/uploads',
        'public/uploads/news',
        'public/uploads/pages',
        'public/uploads/settings',
        'public/uploads/account-avatars',
        'public/uploads/game-assets',
    ] as $relativeUploadPath) {
        assertInstaller(is_dir($temp.'/'.$relativeUploadPath), "The installer must create {$relativeUploadPath}.");
        assertInstaller(($uploadCheckMap[$relativeUploadPath]['ok'] ?? false) === true, "The installer must verify write access for {$relativeUploadPath}.");
    }

    file_put_contents($temp.'/.env', 'APP_DEBUG=false
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
');
    file_put_contents($temp.'/installed.lock', '{}');
    $securityChecks = postInstallSecurityChecks($temp, $publicRoot, $temp.'/.env', $temp.'/installed.lock', $text);
    assertInstaller(count($securityChecks) === 9, 'The completed installer must report the full security checklist.');
    assertInstaller($securityChecks[0]['status'] === 'ok', 'A standard private core must pass the post-install location check.');
    assertInstaller($securityChecks[2]['status'] === 'ok', 'APP_DEBUG=false must pass the post-install debug check.');
    assertInstaller($securityChecks[3]['status'] === 'ok', 'HTTPS and secure cookies must pass the post-install transport check.');
    assertInstaller(str_contains(securityReviewBody($text, $securityChecks), 'Installation security review'), 'The final page must render the security review.');
    assertInstaller(str_contains(completeBody($text, [
        'complete' => [
            'email' => 'owner@example.test',
            'admin_url' => 'https://example.test/admin',
            'site_url' => 'https://example.test/',
            'security_checks' => $securityChecks,
        ],
    ]), '/install'), 'The completed installer must instruct the owner to remove the public install directory.');
    assertInstaller(str_contains(installedBody($text, $securityChecks), '/install'), 'The locked installer page must repeat the install-directory cleanup instruction.');

    $legacyCompatibleEntries = [
        dirname(__DIR__, 4).'/public/index.php',
        dirname(__DIR__, 4).'/public/install/index.php',
        dirname(__DIR__, 2).'/shared-hosting/public/index.php',
        dirname(__DIR__, 2).'/shared-hosting/public/install/index.php',
    ];
    foreach ($legacyCompatibleEntries as $entry) {
        $entrySource = file_get_contents($entry);
        assertInstaller(is_string($entrySource), 'Every public entry point must be readable.');
        assertInstaller(str_contains($entrySource, "version_compare(PHP_VERSION, '8.3.0', '<')"), 'Every public entry point must show a readable PHP version error before Laravel starts.');
        assertInstaller(! str_contains($entrySource, '??'), 'The compatibility entry point must not contain null-coalescing syntax unsupported by PHP 5.5.');
        assertInstaller(! str_contains($entrySource, 'declare(strict_types=1)'), 'The compatibility entry point must not contain strict_types syntax unsupported by old PHP runtimes.');
    }

    $generic = publicInstallerError(new RuntimeException('raw SQL and /private/path'), $text, 'ABC12345');
    assertInstaller(! str_contains($generic, 'raw SQL'), 'Unexpected internal errors must not be exposed to the browser.');
    assertInstaller(str_contains($generic, 'ABC12345'), 'Generic errors must include a support reference code.');

    echo "Web installer regression checks passed.\n";
} finally {
    if (is_dir($temp)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($temp);
    }
}
