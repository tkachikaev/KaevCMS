<?php

declare(strict_types=1);

$options = getopt('', [
    'root:',
    'output:',
    'minimum:',
    'maximum:',
    'target::',
    'name::',
    'delete-file::',
    'previous-root::',
    'update-history',
    'changelog-file::',
]);

if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}

$root = realpath((string) ($options['root'] ?? ''));
$output = (string) ($options['output'] ?? '');
$minimum = trim((string) ($options['minimum'] ?? ''));
$maximum = trim((string) ($options['maximum'] ?? ''));
$target = trim((string) ($options['target'] ?? ''));
$name = trim((string) ($options['name'] ?? ''));
$deleteFile = (string) ($options['delete-file'] ?? '');
$previousRootOption = trim((string) ($options['previous-root'] ?? ''));
$updateHistory = array_key_exists('update-history', $options);

if (! is_string($root) || ! is_dir($root)) {
    fwrite(STDERR, "--root must point to an extracted KaevCMS release.\n");
    exit(1);
}

if ($output === '' || ! validVersion($minimum) || ! validVersion($maximum) || version_compare($minimum, $maximum, '>')) {
    fwrite(STDERR, "Usage: php deployment/updates/build-package.php --root=PATH --output=FILE.zip --minimum=0.32.0 --maximum=0.32.18 [--target=0.33.0] [--previous-root=PATH]\n");
    exit(1);
}

if ($target === '') {
    $versionPath = $root.DIRECTORY_SEPARATOR.'VERSION';
    $target = is_file($versionPath) ? trim((string) file_get_contents($versionPath)) : '';
}

if (! validVersion($target) || version_compare($target, $maximum, '<=')) {
    fwrite(STDERR, "The target version must be valid and newer than --maximum.\n");
    exit(1);
}

if (basename($output) === $output) {
    $output = getcwd().DIRECTORY_SEPARATOR.$output;
}
$outputDirectory = dirname($output);
if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create the update package output directory.');
}

$files = collectReleaseFiles($root, $output);
$targetLogicalPaths = logicalTargets($files);
$deletionHistory = readDeletionHistory($deleteFile);
$automaticDelete = [];

if ($previousRootOption !== '') {
    $previousRoot = realpath($previousRootOption);
    if (! is_string($previousRoot) || ! is_dir($previousRoot)) {
        throw new RuntimeException('--previous-root must point to an extracted KaevCMS release.');
    }

    $previousVersionPath = $previousRoot.DIRECTORY_SEPARATOR.'VERSION';
    $previousVersion = is_file($previousVersionPath) ? trim((string) file_get_contents($previousVersionPath)) : '';
    if (! validVersion($previousVersion) || version_compare($previousVersion, $target, '>=')) {
        throw new RuntimeException('The previous release version is invalid or not older than the target release.');
    }

    $previousLogicalPaths = logicalTargets(collectReleaseFiles($previousRoot, ''));
    $automaticDelete = array_values(array_diff($previousLogicalPaths, $targetLogicalPaths));
    sort($automaticDelete, SORT_STRING);
    $deletionHistory[$target] = array_values(array_unique(array_merge(
        $deletionHistory[$target] ?? [],
        $automaticDelete,
    )));

    if ($updateHistory && $deleteFile !== '') {
        writeDeletionHistory($deleteFile, $deletionHistory);
    }
}

$delete = filterActiveDeletions(
    cumulativeDeletions($deletionHistory, $minimum, $target),
    $targetLogicalPaths,
);
$changelog = readStringList((string) ($options['changelog-file'] ?? ''));
if (count($changelog) > 200) {
    throw new RuntimeException('The update changelog cannot contain more than 200 entries.');
}
foreach ($changelog as $entry) {
    if (mb_strlen($entry) > 1000) {
        throw new RuntimeException('An update changelog entry cannot exceed 1000 characters.');
    }
}
$packageId = strtolower((string) preg_replace('/[^a-z0-9._-]+/i', '-', 'kaevcms-'.$target));
$name = $name !== '' ? $name : 'KaevCMS '.$target;

$zip = new ZipArchive;
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Unable to create the update package ZIP archive.');
}

$manifestFiles = [];
$manifestTargets = [];
try {
    foreach ($files as $relative) {
        $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $logicalTarget = logicalTarget($relative);
        if (version_compare($minimum, '0.32.9', '<=') && ! legacyWebUpdaterAcceptsTarget($logicalTarget)) {
            throw new RuntimeException("The cumulative package target is rejected by the oldest supported Web Updater: {$logicalTarget}");
        }

        $source = 'payload/'.$logicalTarget;

        if (! $zip->addFile($absolute, $source)) {
            throw new RuntimeException("Unable to add release file to update package: {$relative}");
        }

        $hash = hash_file('sha256', $absolute);
        $size = filesize($absolute);
        if (! is_string($hash) || ! is_int($size)) {
            throw new RuntimeException("Unable to read release file metadata: {$relative}");
        }

        $manifestFiles[] = [
            'source' => $source,
            'target' => $logicalTarget,
            'sha256' => $hash,
            'size' => $size,
        ];
        $manifestTargets[strtolower($logicalTarget)] = true;
    }

    $validatedDelete = [];
    $seenDelete = [];
    foreach ($delete as $targetToDelete) {
        if (! validLogicalTarget($targetToDelete)) {
            throw new RuntimeException("Unsafe update deletion path: {$targetToDelete}");
        }

        $targetKey = strtolower($targetToDelete);
        if (isset($manifestTargets[$targetKey])) {
            throw new RuntimeException("A release path cannot be replaced and deleted: {$targetToDelete}");
        }

        foreach (array_keys($manifestTargets) as $fileTarget) {
            if (str_starts_with($targetKey.'/', $fileTarget.'/')) {
                throw new RuntimeException("A release cannot replace a file and delete a path below it: {$targetToDelete}");
            }
        }

        if (! isset($seenDelete[$targetKey])) {
            $seenDelete[$targetKey] = true;
            $validatedDelete[] = $targetToDelete;
        }
    }

    $manifest = [
        'schema' => 1,
        'package_id' => $packageId,
        'name' => $name,
        'target_version' => $target,
        'minimum_version' => $minimum,
        'maximum_version' => $maximum,
        'requires' => [
            'php' => '8.3.0',
            'extensions' => ['dom', 'fileinfo', 'mbstring', 'openssl', 'pdo', 'zip'],
        ],
        'migrate' => true,
        'files' => $manifestFiles,
        'delete' => $validatedDelete,
        'changelog' => $changelog,
    ];

    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (! is_string($encoded) || ! $zip->addFromString('kaevcms-update.json', $encoded."\n")) {
        throw new RuntimeException('Unable to write the update package manifest.');
    }
} finally {
    $zip->close();
}

$archiveHash = hash_file('sha256', $output);
if (! is_string($archiveHash)) {
    throw new RuntimeException('Unable to calculate the update package checksum.');
}

fwrite(STDOUT, "Created: {$output}\n");
fwrite(STDOUT, "Target: {$target}\n");
fwrite(STDOUT, 'Files: '.count($manifestFiles)."\n");
fwrite(STDOUT, 'Deletions: '.count($validatedDelete)."\n");
fwrite(STDOUT, 'New deletions detected: '.count($automaticDelete)."\n");
fwrite(STDOUT, "SHA256: {$archiveHash}\n");

/** @return list<string> */
function collectReleaseFiles(string $root, string $output): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('Release packages cannot contain symbolic links.');
        }

        if (! $file->isFile()) {
            continue;
        }

        $absolute = $file->getPathname();
        if ($output !== '' && samePath($absolute, $output)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
        if (excluded($relative)) {
            continue;
        }

        $files[] = $relative;
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * @param  list<string>  $files
 * @return list<string>
 */
function logicalTargets(array $files): array
{
    $targets = array_map(logicalTarget(...), $files);
    sort($targets, SORT_STRING);

    return array_values(array_unique($targets));
}

function logicalTarget(string $relative): string
{
    return str_starts_with($relative, 'public/')
        ? 'public/'.substr($relative, 7)
        : 'core/'.$relative;
}

function excluded(string $path): bool
{
    $exact = [
        '.env',
        '.env.example',
        'database/database.sqlite',
        'bootstrap/kaevcms-public-path.php',
        'public/uploads/.gitignore',
        'public/uploads/.htaccess',
    ];

    if (in_array($path, $exact, true)) {
        return true;
    }

    foreach ([
        '.git/',
        'node_modules/',
        'vendor/',
        'storage/',
        'dist/',
        'public/uploads/',
        'bootstrap/cache/',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

/** @return array<string, list<string>> */
function readDeletionHistory(string $path): array
{
    if ($path === '') {
        return [];
    }

    $contents = file_get_contents($path);
    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read deletion history: {$path}");
    }

    $decoded = json_decode($contents, true);
    if (! is_array($decoded)) {
        throw new RuntimeException("Deletion history must be valid JSON: {$path}");
    }

    if (array_is_list($decoded)) {
        return ['0.0.1' => normalizeStringList($decoded)];
    }

    $history = [];
    foreach ($decoded as $version => $paths) {
        if (! is_string($version) || ! validVersion($version) || ! is_array($paths)) {
            throw new RuntimeException('Deletion history contains an invalid release entry.');
        }
        $history[$version] = normalizeStringList($paths);
    }
    uksort($history, 'version_compare');

    return $history;
}

/** @param  array<string, list<string>>  $history */
function writeDeletionHistory(string $path, array $history): void
{
    uksort($history, 'version_compare');
    $encoded = json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (! is_string($encoded) || file_put_contents($path, $encoded."\n", LOCK_EX) === false) {
        throw new RuntimeException("Unable to write deletion history: {$path}");
    }
}

/**
 * @param  array<string, list<string>>  $history
 * @return list<string>
 */
function cumulativeDeletions(array $history, string $minimum, string $target): array
{
    $result = [];
    foreach ($history as $version => $paths) {
        if (version_compare($version, $minimum, '<=') || version_compare($version, $target, '>')) {
            continue;
        }
        foreach ($paths as $path) {
            $result[] = $path;
        }
    }

    $result = array_values(array_unique($result));
    sort($result, SORT_STRING);

    return $result;
}

/**
 * @param  list<string>  $deletions
 * @param  list<string>  $activeTargets
 * @return list<string>
 */
function filterActiveDeletions(array $deletions, array $activeTargets): array
{
    $result = [];
    foreach ($deletions as $deletion) {
        $deletionKey = strtolower($deletion);
        $active = false;
        foreach ($activeTargets as $target) {
            $targetKey = strtolower($target);
            if ($targetKey === $deletionKey || str_starts_with($targetKey, $deletionKey.'/')) {
                $active = true;
                break;
            }
        }

        if (! $active) {
            $result[] = $deletion;
        }
    }

    return $result;
}

/** @return list<string> */
function readStringList(string $path): array
{
    if ($path === '') {
        return [];
    }

    $contents = file_get_contents($path);
    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read list file: {$path}");
    }

    $decoded = json_decode($contents, true);
    if (is_array($decoded)) {
        $values = $decoded;
    } else {
        $values = preg_split('/\R/', $contents) ?: [];
    }

    return normalizeStringList($values);
}

/**
 * @param  array<mixed>  $values
 * @return list<string>
 */
function normalizeStringList(array $values): array
{
    $result = [];
    foreach ($values as $value) {
        if (! is_string($value) || trim($value) === '' || str_starts_with(trim($value), '#')) {
            continue;
        }
        $result[] = trim($value);
    }

    return array_values(array_unique($result));
}

function validLogicalTarget(string $path): bool
{
    if ($path === ''
        || str_contains($path, "\0")
        || str_contains($path, '\\')
        || str_contains($path, ':')
        || str_starts_with($path, '/')
        || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        return false;
    }

    if (! str_starts_with($path, 'core/') && ! str_starts_with($path, 'public/')) {
        return false;
    }

    $segments = explode('/', $path);
    if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        return false;
    }

    $normalized = strtolower($path);
    foreach ($segments as $segment) {
        $segment = strtolower($segment);
        if (($segment === '.env' || str_starts_with($segment, '.env.'))
            && $normalized !== 'core/.env.example') {
            return false;
        }
    }

    if (in_array($normalized, ['public/uploads/.gitignore', 'public/uploads/.htaccess'], true)) {
        return true;
    }

    foreach ([
        'core/.git',
        'core/storage',
        'core/vendor',
        'core/public',
        'core/bootstrap/kaevcms-public-path.php',
        'core/bootstrap/cache',
        'public/uploads',
        'public/storage',
        'public/kaevcms-path.php',
    ] as $forbidden) {
        if ($normalized === $forbidden || str_starts_with($normalized, $forbidden.'/')) {
            return false;
        }
    }

    return ! (str_starts_with($normalized, 'core/database/') && str_ends_with($normalized, '.sqlite'));
}

function legacyWebUpdaterAcceptsTarget(string $path): bool
{
    if ($path === ''
        || str_ends_with($path, '/')
        || str_contains($path, "\0")
        || str_contains($path, '\\')
        || str_contains($path, ':')
        || str_starts_with($path, '/')
        || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        return false;
    }

    if (! str_starts_with($path, 'core/') && ! str_starts_with($path, 'public/')) {
        return false;
    }

    $segments = explode('/', rtrim($path, '/'));
    if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        return false;
    }

    $normalized = strtolower($path);
    foreach ($segments as $segment) {
        $segment = strtolower($segment);
        if ($segment === '.env' || str_starts_with($segment, '.env.')) {
            return false;
        }
    }

    if (str_starts_with($normalized, 'core/database/') && str_ends_with($normalized, '.sqlite')) {
        return false;
    }

    foreach ([
        'core/.git',
        'core/storage',
        'core/vendor',
        'core/public',
        'core/bootstrap/kaevcms-public-path.php',
        'core/bootstrap/cache',
        'public/uploads',
        'public/storage',
        'public/kaevcms-path.php',
    ] as $forbidden) {
        if ($normalized === $forbidden || str_starts_with($normalized, $forbidden.'/')) {
            return false;
        }
    }

    return true;
}

function samePath(string $left, string $right): bool
{
    $left = rtrim(str_replace('\\', '/', $left), '/');
    $right = rtrim(str_replace('\\', '/', $right), '/');

    return PHP_OS_FAMILY === 'Windows'
        ? strtolower($left) === strtolower($right)
        : $left === $right;
}

function validVersion(string $version): bool
{
    return preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $version) === 1;
}
