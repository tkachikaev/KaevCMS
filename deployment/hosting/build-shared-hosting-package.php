<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This packaging tool may only be run from PHP CLI.\n");
    exit(1);
}

if (defined('KAEVCMS_PACKAGE_BUILDER_FUNCTIONS_ONLY')) {
    return;
}

$projectRoot = dirname(__DIR__, 2);
$options = parsePackageOptions(array_slice($argv, 1));
$version = trim((string) @file_get_contents($projectRoot.'/VERSION'));

if ($version === '' || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
    failPackage('VERSION is missing or invalid.');
}
if (! is_file($projectRoot.'/vendor/autoload.php')) {
    failPackage('vendor/autoload.php is missing. Run Composer before building a hosting package.');
}

$coreDirectory = validateDirectoryName($options['core-dir'] ?? 'kaevcms-core', 'core-dir');
$publicDirectory = validateDirectoryName($options['public-dir'] ?? 'public_html', 'public-dir');
if ($coreDirectory === $publicDirectory) {
    failPackage('core-dir and public-dir must be different.');
}

$outputDirectory = isset($options['output'])
    ? absolutePackagePath((string) $options['output'], getcwd() ?: $projectRoot)
    : $projectRoot.'/dist';
$packageDirectory = $outputDirectory.'/KaevCMS-'.$version.'-shared-hosting';
$zipPath = $outputDirectory.'/KaevCMS-'.$version.'-shared-hosting.zip';

if (! packageOutputAllowed($projectRoot, $outputDirectory)) {
    failPackage('An output directory inside the project is only allowed under dist/. Use --output=dist or a directory outside the project.');
}

removePackagePath($packageDirectory);
if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
    failPackage('Unable to create output directory: '.$outputDirectory);
}
if (! mkdir($packageDirectory, 0775, true) && ! is_dir($packageDirectory)) {
    failPackage('Unable to create package directory.');
}

$coreTarget = $packageDirectory.'/'.$coreDirectory;
$publicTarget = $packageDirectory.'/'.$publicDirectory;
mkdir($coreTarget, 0775, true);
mkdir($publicTarget, 0775, true);

$excluded = [
    '.env', '.git', '.github', 'auth.json', 'composer.phar', 'dist', 'node_modules',
    'public', 'storage', 'tests', 'bootstrap/cache', 'database/database.sqlite',
    'phpunit.xml', 'phpstan.neon', 'package.json', 'package-lock.json',
    'playwright.config.mjs', '.phpunit.cache', '.phpunit.result.cache', 'npm-debug.log',
    'deployment/windows', 'deployment/vds',
];
copyPackageTree($projectRoot, $coreTarget, $excluded);
createCleanRuntimeSkeleton($coreTarget);
copyPackageTree($projectRoot.'/public', $publicTarget, ['uploads', 'storage', 'hot']);
createCleanPublicRuntimeSkeleton($publicTarget);

copy($projectRoot.'/deployment/hosting/shared-hosting/public/index.php', $publicTarget.'/index.php');
copy($projectRoot.'/deployment/hosting/shared-hosting/public/.htaccess', $publicTarget.'/.htaccess');
ensurePackageDirectory($publicTarget.'/install');
copy($projectRoot.'/deployment/hosting/shared-hosting/public/install/index.php', $publicTarget.'/install/index.php');

$pathTemplatePath = $projectRoot.'/deployment/hosting/shared-hosting/public/kaevcms-path.php.template';
$pathTemplate = @file_get_contents($pathTemplatePath);
if (! is_string($pathTemplate)) {
    failPackage('Shared-hosting core path template is missing.');
}
$pathConfig = str_replace('{{CORE_DIRECTORY}}', $coreDirectory, $pathTemplate);
file_put_contents($publicTarget.'/kaevcms-path.php', $pathConfig, LOCK_EX);

$bootstrapOverride = "<?php\n\ndeclare(strict_types=1);\n\nreturn dirname(__DIR__, 2).'/".addslashes($publicDirectory)."';\n";
file_put_contents($coreTarget.'/bootstrap/kaevcms-public-path.php', $bootstrapOverride, LOCK_EX);

$instructions = <<<TXT
KaevCMS {$version} — shared-hosting package

РУССКИЙ
1. В панели хостинга узнайте фактическую публичную папку домена (Document Root).
2. Распакуйте архив в РОДИТЕЛЬСКИЙ каталог так, чтобы рядом находились:
   - {$coreDirectory}/ — закрытое ядро приложения;
   - {$publicDirectory}/ — публичная папка домена.
3. Домен должен быть направлен только на {$publicDirectory}/.
4. Включите PHP 8.3+, необходимые расширения и HTTPS.
5. Откройте домен и завершите установку через /install/.
6. После установки проверьте итоговый отчёт безопасности. Не назначайте 0777 всему проекту.

Примеры сборки в PowerShell:
- Beget/cPanel с public_html:
  .\deployment\windows\build-shared-hosting-package.ps1
- Jino или другой каталог домена:
  .\deployment\windows\build-shared-hosting-package.ps1 -PublicDirectoryName example.hosting.test
- Собственное имя закрытого ядра:
  .\deployment\windows\build-shared-hosting-package.ps1 -CoreDirectoryName private-kaevcms

ENGLISH
1. Find the domain's actual public directory (Document Root) in the hosting control panel.
2. Extract the archive into its PARENT directory so these directories are siblings:
   - {$coreDirectory}/ — private application core;
   - {$publicDirectory}/ — domain public directory.
3. The domain must point only to {$publicDirectory}/.
4. Enable PHP 8.3+, required extensions, and HTTPS.
5. Open the domain and complete /install/.
6. Review the final security report. Do not assign 0777 to the whole project.

PowerShell build examples:
- Beget/cPanel with public_html:
  .\deployment\windows\build-shared-hosting-package.ps1
- Jino or another domain directory:
  .\deployment\windows\build-shared-hosting-package.ps1 -PublicDirectoryName example.hosting.test
- Custom private core name:
  .\deployment\windows\build-shared-hosting-package.ps1 -CoreDirectoryName private-kaevcms

TXT;

file_put_contents($packageDirectory.'/INSTALL-SHARED-HOSTING.txt', $instructions, LOCK_EX);

if (! isset($options['no-zip']) && (is_dir($projectRoot.'/vendor/phpunit') || is_dir($projectRoot.'/vendor/larastan'))) {
    fwrite(STDOUT, "WARNING: direct PHP packaging copies the current vendor directory. Use the Windows wrapper for an automatic production-only vendor, or run Composer with --no-dev in the prepared core before archiving.\n");
}

if (! isset($options['no-zip'])) {
    if (! class_exists(ZipArchive::class)) {
        fwrite(STDOUT, "ZIP extension is unavailable; package directory was created without an archive.\n");
    } else {
        @unlink($zipPath);
        createPackageZip($packageDirectory, $zipPath);
        file_put_contents($zipPath.'.sha256', hash_file('sha256', $zipPath).'  '.basename($zipPath)."\n", LOCK_EX);
        fwrite(STDOUT, "Archive: {$zipPath}\n");
    }
}

fwrite(STDOUT, "Package directory: {$packageDirectory}\n");
fwrite(STDOUT, "Core directory: {$coreDirectory}\nPublic directory: {$publicDirectory}\n");

/** @return array<string, string|bool> */
function parsePackageOptions(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if ($argument === '--no-zip') {
            $options['no-zip'] = true;

            continue;
        }
        if (preg_match('/^--(output|core-dir|public-dir)=(.+)$/', $argument, $matches) === 1) {
            $options[$matches[1]] = $matches[2];

            continue;
        }
        failPackage('Unknown argument: '.$argument);
    }

    return $options;
}

function validateDirectoryName(string $value, string $option): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) !== 1 || $value === '.' || $value === '..') {
        failPackage('Invalid --'.$option.' value. Use only letters, digits, dots, underscores, and hyphens.');
    }

    return $value;
}

function absolutePackagePath(string $path, string $base): string
{
    $combined = preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1
        ? $path
        : rtrim($base, '/\\').'/'.$path;

    return canonicalPackagePath($combined);
}

function canonicalPackagePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_starts_with($path, '//')) {
        failPackage('Package paths must be absolute local filesystem paths.');
    }

    $prefix = '';
    if (preg_match('/\A([A-Za-z]:)(?:\/|$)/', $path, $matches) === 1) {
        $prefix = strtoupper($matches[1]).'/';
        $path = substr($path, strlen($matches[1]));
    } elseif (str_starts_with($path, '/')) {
        $prefix = '/';
    } else {
        failPackage('Package paths must resolve to an absolute path.');
    }

    $segments = [];
    foreach (explode('/', ltrim($path, '/')) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments === []) {
                failPackage('Package path escapes its filesystem root.');
            }
            array_pop($segments);

            continue;
        }
        $segments[] = $segment;
    }

    $normalized = $prefix.implode('/', $segments);

    return rtrim($normalized, '/') ?: $prefix;
}

function packageOutputAllowed(string $root, string $output): bool
{
    $relative = packageRelativePath($root, $output);
    if ($relative === null) {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedOutput = rtrim(str_replace('\\', '/', $output), '/');

        return $normalizedOutput !== $normalizedRoot;
    }

    return $relative === 'dist' || str_starts_with($relative, 'dist/');
}

function packageRelativePath(string $root, string $path): ?string
{
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
    if ($normalizedPath === $normalizedRoot) {
        return null;
    }
    $prefix = $normalizedRoot.'/';
    if (! str_starts_with($normalizedPath, $prefix)) {
        return null;
    }

    return substr($normalizedPath, strlen($prefix));
}

/** @param list<string> $excluded */
function copyPackageTree(string $source, string $destination, array $excluded, string $relative = ''): void
{
    ensurePackageDirectory($destination);
    $iterator = new DirectoryIterator($source);
    foreach ($iterator as $item) {
        if ($item->isDot()) {
            continue;
        }
        $itemRelative = ltrim($relative.'/'.$item->getFilename(), '/');
        $normalized = str_replace('\\', '/', $itemRelative);
        if (packagePathExcluded($normalized, $excluded)) {
            continue;
        }
        if ($item->isLink()) {
            failPackage('Symbolic links are not allowed in the hosting package: '.$normalized);
        }
        $target = $destination.'/'.$item->getFilename();
        if ($item->isDir()) {
            copyPackageTree($item->getPathname(), $target, $excluded, $itemRelative);

            continue;
        }
        if (! copy($item->getPathname(), $target)) {
            failPackage('Unable to copy '.$normalized);
        }
    }
}

/** @param list<string> $excluded */
function packagePathExcluded(string $path, array $excluded): bool
{
    if ($path !== '.env.example' && ($path === '.env' || str_starts_with($path, '.env.'))) {
        return true;
    }

    foreach ($excluded as $entry) {
        if ($path === $entry || str_starts_with($path, $entry.'/')) {
            return true;
        }
    }

    return false;
}

function createCleanRuntimeSkeleton(string $coreTarget): void
{
    foreach ([
        'bootstrap/cache',
        'storage/app/private',
        'storage/app/public',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
    ] as $relative) {
        $directory = $coreTarget.'/'.$relative;
        ensurePackageDirectory($directory);
        file_put_contents($directory.'/.gitignore', "*\n!.gitignore\n", LOCK_EX);
    }
}

function createCleanPublicRuntimeSkeleton(string $publicTarget): void
{
    $uploads = $publicTarget.'/uploads';
    ensurePackageDirectory($uploads);
    file_put_contents($uploads.'/.gitignore', "*\n!.gitignore\n!.htaccess\n", LOCK_EX);
    file_put_contents(
        $uploads.'/.htaccess',
        "<FilesMatch \"\\.(?:php[0-9]?|phtml|phar)$\">\n"
        ."    <IfModule mod_authz_core.c>\n"
        ."        Require all denied\n"
        ."    </IfModule>\n"
        ."    <IfModule !mod_authz_core.c>\n"
        ."        Order allow,deny\n"
        ."        Deny from all\n"
        ."    </IfModule>\n"
        ."</FilesMatch>\n",
        LOCK_EX,
    );
}

function ensurePackageDirectory(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
        failPackage('Unable to create directory: '.$path);
    }
}

function removePackagePath(string $path): void
{
    if (! file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);

        return;
    }
    $items = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
    foreach ($items as $item) {
        removePackagePath($item->getPathname());
    }
    @rmdir($path);
}

function createPackageZip(string $sourceDirectory, string $zipPath): void
{
    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        failPackage('Unable to create ZIP archive: '.$zipPath);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
        $relative = portablePackageRelativePath($sourceDirectory, $item->getPathname());
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($item->getPathname(), $relative);
        }
    }
    if (! $zip->close()) {
        failPackage('Unable to finalize ZIP archive.');
    }
}

function portablePackageRelativePath(string $sourceDirectory, string $path): string
{
    $normalizedSource = rtrim(str_replace('\\', '/', $sourceDirectory), '/');
    $normalizedPath = str_replace('\\', '/', $path);
    $prefix = $normalizedSource.'/';

    if (! str_starts_with($normalizedPath, $prefix)) {
        failPackage('ZIP entry is outside the package source directory: '.$path);
    }

    $relative = substr($normalizedPath, strlen($prefix));
    if ($relative === '') {
        failPackage('ZIP entry path is empty: '.$path);
    }

    return $relative;
}

function failPackage(string $message): never
{
    fwrite(STDERR, 'ERROR: '.$message."\n");
    exit(1);
}
