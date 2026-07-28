<?php

declare(strict_types=1);

if (! class_exists(ZipArchive::class)) {
    throw new RuntimeException('The PHP zip extension is required for Web Updater package regression tests.');
}

$projectRoot = dirname(__DIR__, 2);
$release = readJson($projectRoot.'/release.json');
$deletionHistory = readJson(__DIR__.'/deletions.json');

$currentVersion = requireVersion($release['version'] ?? null, 'release version');
$previousVersion = requireVersion($release['previous_version'] ?? null, 'previous release version');
$minimumVersion = requireVersion($release['cumulative_base_version'] ?? null, 'cumulative base version');

if (version_compare($previousVersion, $currentVersion, '>=')) {
    throw new RuntimeException('The release contract previous version must be older than the target version.');
}

$versions = array_keys($deletionHistory);
usort($versions, 'version_compare');
$previousHistoryVersion = null;
foreach ($versions as $version) {
    requireVersion($version, 'deletion history version');
    $paths = $deletionHistory[$version] ?? null;
    if (! is_array($paths) || array_is_list($paths) === false) {
        throw new RuntimeException("Deletion history entry {$version} must be a path list.");
    }

    $normalized = [];
    foreach ($paths as $path) {
        if (! is_string($path) || ! validLogicalPath($path)) {
            throw new RuntimeException("Deletion history contains an unsafe path for {$version}.");
        }
        $normalized[] = $path;
    }
    if (count(array_unique($normalized)) !== count($normalized)) {
        throw new RuntimeException("Deletion history entry {$version} contains duplicate paths.");
    }

    if ($previousHistoryVersion !== null) {
        $expectedApply = "core/deployment/windows/apply-{$previousHistoryVersion}.ps1";
        if (! in_array($expectedApply, $normalized, true)) {
            throw new RuntimeException("Deletion history entry {$version} does not remove {$expectedApply}.");
        }
    }

    $previousHistoryVersion = $version;
}

$currentDeletions = $deletionHistory[$currentVersion] ?? null;
if (! is_array($currentDeletions)) {
    throw new RuntimeException("Deletion history does not contain the target release {$currentVersion}.");
}
if (! in_array("core/deployment/windows/apply-{$previousVersion}.ps1", $currentDeletions, true)) {
    throw new RuntimeException('Current deletion history does not remove the previous apply script.');
}
if (in_array('public/assets/account', $currentDeletions, true)) {
    throw new RuntimeException('Current deletion history must preserve the shared account runtime.');
}

$tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kaevcms-update-builder-'.bin2hex(random_bytes(8));
$currentRoot = $tempRoot.DIRECTORY_SEPARATOR.'current';
$previousRoot = $tempRoot.DIRECTORY_SEPARATOR.'previous';
$output = $tempRoot.DIRECTORY_SEPARATOR.'update.zip';
$deleteFile = $tempRoot.DIRECTORY_SEPARATOR.'deletions.json';

try {
    writeFixture($currentRoot, [
        'VERSION' => '9.9.9',
        'release.json' => json_encode(['schema' => 1, 'version' => '9.9.9', 'released_at' => '2026-07-28'], JSON_THROW_ON_ERROR)."\n",
        'app/example.php' => "<?php\n",
        'public/index.php' => "<?php\n",
        '.env' => "APP_KEY=secret\n",
        'storage/logs/private.log' => "secret\n",
        'public/uploads/account-avatars/avatar.webp' => "owner-data\n",
    ]);
    writeFixture($previousRoot, [
        'VERSION' => '9.9.8',
        'app/example.php' => "<?php\n",
        'app/obsolete.php' => "<?php\n",
        'public/index.php' => "<?php\n",
    ]);
    file_put_contents($deleteFile, "{}\n");

    $command = [
        PHP_BINARY,
        __DIR__.'/build-package.php',
        '--root='.$currentRoot,
        '--output='.$output,
        '--minimum=9.9.7',
        '--maximum=9.9.8',
        '--target=9.9.9',
        '--previous-root='.$previousRoot,
        '--delete-file='.$deleteFile,
        '--update-history',
    ];
    runCommand($command);

    $mismatchCommand = $command;
    $targetArgument = array_search('--target=9.9.9', $mismatchCommand, true);
    if (! is_int($targetArgument)) {
        throw new RuntimeException('Target argument was not found in the package-builder fixture.');
    }
    $mismatchCommand[$targetArgument] = '--target=9.9.10';
    runCommandExpectFailure($mismatchCommand, 'does not match release.json');

    $invalidDateRoot = $tempRoot.DIRECTORY_SEPARATOR.'invalid-date';
    writeFixture($invalidDateRoot, [
        'VERSION' => '9.9.9',
        'release.json' => json_encode([
            'schema' => 1,
            'version' => '9.9.9',
            'released_at' => 'not-a-date',
        ], JSON_THROW_ON_ERROR)."\n",
        'app/example.php' => "<?php\n",
        'public/index.php' => "<?php\n",
    ]);
    $invalidDateCommand = $command;
    $rootArgument = array_search('--root='.$currentRoot, $invalidDateCommand, true);
    if (! is_int($rootArgument)) {
        throw new RuntimeException('Root argument was not found in the package-builder invalid-date fixture.');
    }
    $invalidDateCommand[$rootArgument] = '--root='.$invalidDateRoot;
    runCommandExpectFailure($invalidDateCommand, 'Release date metadata is invalid');

    $missingContractRoot = $tempRoot.DIRECTORY_SEPARATOR.'missing-contract';
    writeFixture($missingContractRoot, [
        'VERSION' => '9.9.9',
        'app/example.php' => "<?php\n",
        'public/index.php' => "<?php\n",
    ]);
    $missingContractCommand = $command;
    $rootArgument = array_search('--root='.$currentRoot, $missingContractCommand, true);
    if (! is_int($rootArgument)) {
        throw new RuntimeException('Root argument was not found in the package-builder fixture.');
    }
    $missingContractCommand[$rootArgument] = '--root='.$missingContractRoot;
    runCommandExpectFailure($missingContractCommand, 'missing release.json');

    $zip = new ZipArchive;
    if ($zip->open($output) !== true) {
        throw new RuntimeException('Unable to open the generated Web Updater package.');
    }
    try {
        $manifestContents = $zip->getFromName('kaevcms-update.json');
        if (! is_string($manifestContents)) {
            throw new RuntimeException('Generated package manifest is missing.');
        }
        $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['target_version'] ?? null) !== '9.9.9'
            || ($manifest['minimum_version'] ?? null) !== '9.9.7'
            || ($manifest['maximum_version'] ?? null) !== '9.9.8') {
            throw new RuntimeException('Generated package version range is incorrect.');
        }

        $targets = array_column($manifest['files'] ?? [], 'target');
        sort($targets);
        if ($targets !== ['core/VERSION', 'core/app/example.php', 'core/release.json', 'public/index.php']) {
            throw new RuntimeException('Generated package included protected runtime or owner-data files.');
        }
        if (($manifest['delete'] ?? []) !== ['core/app/obsolete.php']) {
            throw new RuntimeException('Automatic deletion detection did not preserve the expected obsolete path.');
        }

        foreach ($manifest['files'] as $file) {
            if (! isset($file['source'], $file['target'], $file['sha256'], $file['size'])
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $file['sha256'])) {
                throw new RuntimeException('Generated package file metadata is incomplete.');
            }
        }
    } finally {
        $zip->close();
    }

    $fixtureHistory = readJson($deleteFile);
    if (($fixtureHistory['9.9.9'] ?? null) !== ['core/app/obsolete.php']) {
        throw new RuntimeException('Automatic deletion history update is incorrect.');
    }
} finally {
    removeTree($tempRoot);
}

$defaultFilename = sprintf(
    'KaevCMS-cumulative-update-%s-%s-to-%s.zip',
    $minimumVersion,
    $previousVersion,
    $currentVersion,
);
if (! str_starts_with($defaultFilename, 'KaevCMS-cumulative-update-')) {
    throw new RuntimeException('Cumulative package filename contract is invalid.');
}

echo "Web update package builder regression checks completed successfully.\n";

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $contents = file_get_contents($path);
    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read JSON file: {$path}");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException("JSON root must be an object: {$path}");
    }

    return $decoded;
}

function requireVersion(mixed $value, string $label): string
{
    if (! is_string($value) || preg_match('/^\d+\.\d+\.\d+$/', $value) !== 1) {
        throw new RuntimeException("Invalid {$label}.");
    }

    return $value;
}

function validLogicalPath(string $path): bool
{
    return $path !== ''
        && ! str_contains($path, '\\')
        && ! str_contains($path, "\0")
        && ! str_starts_with($path, '/')
        && (str_starts_with($path, 'core/') || str_starts_with($path, 'public/'))
        && ! in_array('..', explode('/', $path), true);
}

/** @param  array<string, string>  $files */
function writeFixture(string $root, array $files): void
{
    foreach ($files as $relative => $contents) {
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create fixture directory: {$directory}");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to create fixture file: {$path}");
        }
    }
}

/** @param  list<string>  $command */
function runCommand(array $command): void
{
    $escaped = implode(' ', array_map(escapeshellarg(...), $command));
    exec($escaped.' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Command failed:\n".implode("\n", $output));
    }
}

/** @param  list<string>  $command */
function runCommandExpectFailure(array $command, string $expectedOutput): void
{
    $escaped = implode(' ', array_map(escapeshellarg(...), $command));
    exec($escaped.' 2>&1', $output, $exitCode);
    $combinedOutput = implode("\n", $output);
    if ($exitCode === 0 || ! str_contains($combinedOutput, $expectedOutput)) {
        throw new RuntimeException("Command did not fail as expected:\n{$combinedOutput}");
    }
}

function removeTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}
