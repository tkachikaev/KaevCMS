<?php

declare(strict_types=1);

function assertUpdateEntrypoint(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @return array{int, string, string} */
function runUpdateEntrypoint(string $script): array
{
    $process = proc_open(
        [PHP_BINARY, $script],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start the PHP entrypoint regression process.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        $exitCode,
        is_string($stdout) ? $stdout : '',
        is_string($stderr) ? $stderr : '',
    ];
}

function removeUpdateEntrypointDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path.DIRECTORY_SEPARATOR.$item;
        if (is_dir($child) && ! is_link($child)) {
            removeUpdateEntrypointDirectory($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

function prepareFakeCore(string $coreRoot, string $expectedPublicRoot): void
{
    foreach ([
        $coreRoot.'/bootstrap',
        $coreRoot.'/vendor',
        $coreRoot.'/storage/framework',
        $coreRoot.'/deployment/hosting/web-installer',
    ] as $directory) {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the entrypoint regression fixture.');
        }
    }

    file_put_contents($coreRoot.'/.env', "APP_ENV=testing\n", LOCK_EX);
    file_put_contents(
        $coreRoot.'/vendor/autoload.php',
        "<?php\nnamespace Illuminate\\Http;\nfinal class Request { public static function capture(): self { return new self; } }\n",
        LOCK_EX,
    );

    $expected = var_export(str_replace('\\', '/', $expectedPublicRoot), true);
    file_put_contents(
        $coreRoot.'/bootstrap/app.php',
        "<?php\nreturn new class({$expected}) {\n"
        ."    public function __construct(private string \$publicPath) {}\n"
        ."    public function usePublicPath(string \$path): void { \$this->publicPath = str_replace('\\\\', '/', \$path); }\n"
        ."    public function handleRequest(object \$request): void { echo 'APP_PUBLIC=' . \$this->publicPath; }\n"
        ."};\n",
        LOCK_EX,
    );

    file_put_contents(
        $coreRoot.'/deployment/hosting/web-installer/installer.php',
        "<?php\n"
        ."echo 'INSTALL_ROOT=' . str_replace('\\\\', '/', KAEVCMS_PROJECT_ROOT);\n"
        ."echo ';INSTALL_PUBLIC=' . str_replace('\\\\', '/', KAEVCMS_PUBLIC_PATH);\n"
        ."echo ';SHARED=' . ((defined('KAEVCMS_SHARED_HOSTING') && KAEVCMS_SHARED_HOSTING === true) ? '1' : '0');\n",
        LOCK_EX,
    );
}

$projectRoot = dirname(__DIR__, 4);
$publicEntry = $projectRoot.'/public/index.php';
$installEntry = $projectRoot.'/public/install/index.php';
$sharedPublicEntry = dirname(__DIR__).'/public/index.php';
$sharedInstallEntry = dirname(__DIR__).'/public/install/index.php';
$standardHtaccess = $projectRoot.'/public/.htaccess';
$sharedHtaccess = dirname(__DIR__).'/public/.htaccess';

assertUpdateEntrypoint(hash_file('sha256', $publicEntry) === hash_file('sha256', $sharedPublicEntry), 'Standard and shared-hosting web entries must stay identical.');
assertUpdateEntrypoint(hash_file('sha256', $installEntry) === hash_file('sha256', $sharedInstallEntry), 'Standard and shared-hosting installer entries must stay identical.');
assertUpdateEntrypoint(hash_file('sha256', $standardHtaccess) === hash_file('sha256', $sharedHtaccess), 'Standard and shared-hosting Apache rules must stay identical.');
assertUpdateEntrypoint(str_contains((string) file_get_contents($standardHtaccess), 'RewriteRule ^kaevcms-path\\.php$ - [F,L]'), 'The unified Apache rules must protect kaevcms-path.php.');

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kaevcms-entrypoint-update-'.bin2hex(random_bytes(8));

try {
    foreach (['public_html', 'a860dbbcc70b.hosting.myjino.ru'] as $publicDirectoryName) {
        $fixtureRoot = $root.DIRECTORY_SEPARATOR.$publicDirectoryName;
        $coreRoot = $fixtureRoot.DIRECTORY_SEPARATOR.'private-kaevcms';
        $publicRoot = $fixtureRoot.DIRECTORY_SEPARATOR.$publicDirectoryName;
        mkdir($publicRoot.'/install', 0775, true);
        prepareFakeCore($coreRoot, $publicRoot);

        copy($publicEntry, $publicRoot.'/index.php');
        copy($installEntry, $publicRoot.'/install/index.php');
        file_put_contents(
            $publicRoot.'/kaevcms-path.php',
            "<?php\nreturn ".var_export($coreRoot, true).";\n",
            LOCK_EX,
        );

        [$webCode, $webOutput, $webError] = runUpdateEntrypoint($publicRoot.'/index.php');
        assertUpdateEntrypoint($webCode === 0, "Unified web entry failed for {$publicDirectoryName}: {$webError}");
        assertUpdateEntrypoint($webOutput === 'APP_PUBLIC='.str_replace('\\', '/', $publicRoot), "Unified web entry lost the actual public directory for {$publicDirectoryName}.");

        [$installCode, $installOutput, $installError] = runUpdateEntrypoint($publicRoot.'/install/index.php');
        assertUpdateEntrypoint($installCode === 0, "Unified installer entry failed for {$publicDirectoryName}: {$installError}");
        assertUpdateEntrypoint(str_contains($installOutput, 'INSTALL_ROOT='.str_replace('\\', '/', $coreRoot)), "Unified installer entry lost the private core path for {$publicDirectoryName}.");
        assertUpdateEntrypoint(str_contains($installOutput, 'INSTALL_PUBLIC='.str_replace('\\', '/', $publicRoot)), "Unified installer entry lost the public path for {$publicDirectoryName}.");
        assertUpdateEntrypoint(str_contains($installOutput, 'SHARED=1'), "Unified installer entry did not detect split hosting for {$publicDirectoryName}.");
    }

    $standardRoot = $root.'/standard-kaevcms';
    $standardPublic = $standardRoot.'/public';
    mkdir($standardPublic.'/install', 0775, true);
    prepareFakeCore($standardRoot, $standardPublic);
    copy($publicEntry, $standardPublic.'/index.php');
    copy($installEntry, $standardPublic.'/install/index.php');

    [$webCode, $webOutput, $webError] = runUpdateEntrypoint($standardPublic.'/index.php');
    assertUpdateEntrypoint($webCode === 0, "Unified standard web entry failed: {$webError}");
    assertUpdateEntrypoint($webOutput === 'APP_PUBLIC='.str_replace('\\', '/', $standardPublic), 'Unified web entry broke the standard layout.');

    [$installCode, $installOutput, $installError] = runUpdateEntrypoint($standardPublic.'/install/index.php');
    assertUpdateEntrypoint($installCode === 0, "Unified standard installer entry failed: {$installError}");
    assertUpdateEntrypoint(str_contains($installOutput, 'SHARED=0'), 'Unified installer entry misdetected the standard layout as split hosting.');
} finally {
    removeUpdateEntrypointDirectory($root);
}

fwrite(STDOUT, "Shared-hosting update entrypoint regression checks passed.\n");
