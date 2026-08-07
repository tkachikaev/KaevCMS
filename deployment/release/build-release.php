<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $artifacts = kaevReleaseBuild(getopt('', [
            'root::',
            'previous:',
            'output-dir::',
        ]));

        foreach ($artifacts as $label => $path) {
            fwrite(STDOUT, sprintf("%s: %s\n", $label, $path));
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Release build failed: '.$exception->getMessage()."\n");
        exit(1);
    }
}

/**
 * @param  array<string, mixed>  $options
 * @return array{full:string,patch:string,cumulative:string,checksums:string}
 */
function kaevReleaseBuild(array $options): array
{
    if (! class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP zip extension is required.');
    }

    $rootOption = trim((string) ($options['root'] ?? dirname(__DIR__, 2)));
    $previousOption = trim((string) ($options['previous'] ?? ''));
    $outputOption = trim((string) ($options['output-dir'] ?? ''));

    $root = realpath($rootOption);
    $previousArchive = realpath($previousOption);
    if (! is_string($root) || ! is_dir($root)) {
        throw new RuntimeException('--root must point to the KaevCMS source tree.');
    }
    if (! is_string($previousArchive) || ! is_file($previousArchive)) {
        throw new RuntimeException('--previous must point to the direct previous full release ZIP.');
    }

    $release = kaevReleaseReadJson($root.'/release.json');
    $version = kaevReleaseRequireVersion($release['version'] ?? null, 'version');
    $previousVersion = kaevReleaseRequireVersion($release['previous_version'] ?? null, 'previous_version');
    $cumulativeBase = kaevReleaseRequireVersion($release['cumulative_base_version'] ?? null, 'cumulative_base_version');
    $recoveryFloor = kaevReleaseRequireVersion($release['recovery_floor_version'] ?? null, 'recovery_floor_version');
    $releasedAt = trim((string) ($release['released_at'] ?? ''));
    $releaseTimestamp = strtotime($releasedAt.' 12:00:00 UTC');

    if ($releasedAt === '' || $releaseTimestamp === false) {
        throw new RuntimeException('release.json contains an invalid released_at date.');
    }
    if (trim((string) file_get_contents($root.'/VERSION')) !== $version) {
        throw new RuntimeException('VERSION does not match release.json.');
    }
    if (version_compare($previousVersion, $version, '>=')) {
        throw new RuntimeException('The direct previous version must be older than the target version.');
    }
    if (version_compare($cumulativeBase, $recoveryFloor, '>')) {
        throw new RuntimeException('The cumulative base cannot be newer than the recovery floor.');
    }
    if (version_compare($recoveryFloor, $previousVersion, '>')) {
        throw new RuntimeException('The recovery floor cannot be newer than the direct previous version.');
    }

    $outputDirectory = $outputOption !== ''
        ? kaevReleaseAbsolutePath($outputOption)
        : $root.DIRECTORY_SEPARATOR.'dist';
    if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
        throw new RuntimeException('Unable to create the release output directory.');
    }
    $outputDirectory = realpath($outputDirectory) ?: $outputDirectory;

    $temporaryRoot = kaevReleaseTemporaryDirectory('kaevcms-release-build-');
    $previousRoot = $temporaryRoot.'/previous';
    $overlayRoot = $temporaryRoot.'/overlay';

    try {
        kaevReleaseExtractZip($previousArchive, $previousRoot);
        $archiveVersionPath = $previousRoot.'/VERSION';
        if (! is_file($archiveVersionPath) || trim((string) file_get_contents($archiveVersionPath)) !== $previousVersion) {
            throw new RuntimeException("The previous full archive must contain KaevCMS {$previousVersion}.");
        }

        $currentFiles = kaevReleaseCollectFiles($root, [$outputDirectory]);
        $previousFiles = kaevReleaseCollectFiles($previousRoot);
        kaevReleaseAssertMetadataMatchesTrees($root, $release, $version, $previousVersion, $previousRoot, $currentFiles, $previousFiles);
        kaevReleaseAssertRequiredFiles($root, $currentFiles);

        $fullPath = $outputDirectory.'/KaevCMS-'.$version.'-full.zip';
        $patchPath = $outputDirectory.'/KaevCMS-'.$previousVersion.'-to-'.$version.'-patch.zip';
        $cumulativeRange = $cumulativeBase === $previousVersion
            ? $cumulativeBase
            : $cumulativeBase.'-'.$previousVersion;
        $cumulativePath = $outputDirectory.'/KaevCMS-cumulative-update-'.$cumulativeRange.'-to-'.$version.'.zip';
        $checksumPath = $outputDirectory.'/KaevCMS-'.$version.'-SHA256SUMS.txt';

        $changedFiles = kaevReleaseChangedFiles($root, $currentFiles, $previousRoot, $previousFiles);
        $repairFiles = kaevReleaseRepairFiles($release['repair_files'] ?? [], $currentFiles);
        kaevReleaseAssertRepairFilesAreExceptional($repairFiles, $changedFiles);
        $changedFiles = array_values(array_unique(array_merge($changedFiles, $repairFiles)));
        sort($changedFiles, SORT_STRING);
        $removedFiles = array_values(array_diff($previousFiles, $currentFiles));
        sort($removedFiles, SORT_STRING);
        kaevReleaseAssertDeletionsDeclared($root, $version, $removedFiles);

        kaevReleaseCreateZip($root, $currentFiles, $fullPath, $releaseTimestamp);
        kaevReleaseCreateZip($root, $changedFiles, $patchPath, $releaseTimestamp);
        kaevReleaseValidateZip($fullPath, $currentFiles);
        kaevReleaseValidateZip($patchPath, $changedFiles);

        kaevReleaseCopyFiles($previousRoot, $previousFiles, $overlayRoot);
        kaevReleaseExtractZip($patchPath, $overlayRoot);
        kaevReleaseApplyDeletions($root, $version, $overlayRoot);
        kaevReleaseAssertTreesMatch($root, $currentFiles, $overlayRoot);

        $updateBuilder = $root.'/deployment/updates/build-package.php';
        if (! is_file($updateBuilder)) {
            throw new RuntimeException('The cumulative Web Update builder is missing.');
        }

        $command = [
            PHP_BINARY,
            $updateBuilder,
            '--root='.$root,
            '--output='.$cumulativePath,
            '--minimum='.$cumulativeBase,
            '--maximum='.$previousVersion,
            '--target='.$version,
            '--delete-file='.$root.'/deployment/updates/deletions.json',
            '--previous-root='.$previousRoot,
        ];
        kaevReleaseRunCommand($command, $root);
        kaevReleaseValidateZip($cumulativePath);

        $artifacts = [$fullPath, $patchPath, $cumulativePath];
        $checksumLines = [];
        foreach ($artifacts as $artifact) {
            $hash = hash_file('sha256', $artifact);
            if (! is_string($hash)) {
                throw new RuntimeException('Unable to calculate SHA256 for '.basename($artifact).'.');
            }
            $checksumLines[] = $hash.'  '.basename($artifact);
        }
        if (file_put_contents($checksumPath, implode("\n", $checksumLines)."\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the checksum file.');
        }

        return [
            'full' => $fullPath,
            'patch' => $patchPath,
            'cumulative' => $cumulativePath,
            'checksums' => $checksumPath,
        ];
    } finally {
        kaevReleaseRemoveTree($temporaryRoot);
    }
}

/**
 * @param  array<string, mixed>  $release
 * @param  list<string>  $currentFiles
 * @param  list<string>  $previousFiles
 */
function kaevReleaseAssertMetadataMatchesTrees(
    string $root,
    array $release,
    string $version,
    string $previousVersion,
    string $previousRoot,
    array $currentFiles,
    array $previousFiles,
): void {
    if (($release['schema'] ?? null) !== 1) {
        throw new RuntimeException('release.json schema must be 1.');
    }

    $expectedApplyScript = 'deployment/windows/apply-'.$version.'.ps1';
    if (($release['apply_script'] ?? null) !== $expectedApplyScript) {
        throw new RuntimeException('release.json apply_script does not match the target version.');
    }
    if (! in_array($expectedApplyScript, $currentFiles, true)) {
        throw new RuntimeException('The target apply script is missing from the release tree.');
    }

    $applyScripts = array_values(array_filter(
        $currentFiles,
        static fn (string $path): bool => preg_match('#^deployment/windows/apply-\d+\.\d+\.\d+\.ps1$#', $path) === 1,
    ));
    if ($applyScripts !== [$expectedApplyScript]) {
        throw new RuntimeException('The target release tree must contain exactly one versioned apply script.');
    }

    $expectedPreviousApplyScript = 'deployment/windows/apply-'.$previousVersion.'.ps1';
    if (($release['previous_apply_script'] ?? null) !== $expectedPreviousApplyScript) {
        throw new RuntimeException('release.json previous_apply_script does not match previous_version.');
    }
    if (! in_array($expectedPreviousApplyScript, $previousFiles, true)) {
        throw new RuntimeException('The previous full archive is missing its apply script.');
    }

    $previousApplyPath = $previousRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $expectedPreviousApplyScript);
    $expectedPreviousApplyHash = kaevReleaseRequireSha256($release['previous_apply_sha256'] ?? null, 'previous_apply_sha256');
    $actualPreviousApplyHash = hash_file('sha256', $previousApplyPath);
    if (! is_string($actualPreviousApplyHash) || ! hash_equals($expectedPreviousApplyHash, $actualPreviousApplyHash)) {
        throw new RuntimeException('release.json previous_apply_sha256 does not match the previous full archive.');
    }

    $previousRelease = kaevReleaseReadJson($previousRoot.'/release.json');
    if (kaevReleaseRequireVersion($previousRelease['version'] ?? null, 'previous archive version') !== $previousVersion) {
        throw new RuntimeException('The previous full archive release.json does not match previous_version.');
    }
    if (($previousRelease['apply_script'] ?? null) !== $expectedPreviousApplyScript) {
        throw new RuntimeException('The previous full archive apply_script is inconsistent.');
    }

    $composer = $release['composer_lock'] ?? null;
    if (! is_array($composer)) {
        throw new RuntimeException('release.json composer_lock must be an object.');
    }
    $expectedPreviousComposerHash = kaevReleaseRequireSha256($composer['previous_sha256'] ?? null, 'composer_lock.previous_sha256');
    $expectedCurrentComposerHash = kaevReleaseRequireSha256($composer['current_sha256'] ?? null, 'composer_lock.current_sha256');
    $previousComposerPath = $previousRoot.'/composer.lock';
    $currentComposerPath = $root.'/composer.lock';
    if (! is_file($previousComposerPath) || ! is_file($currentComposerPath)) {
        throw new RuntimeException('composer.lock must exist in both previous and target release trees.');
    }
    $actualPreviousComposerHash = hash_file('sha256', $previousComposerPath);
    $actualCurrentComposerHash = hash_file('sha256', $currentComposerPath);
    if (! is_string($actualPreviousComposerHash) || ! hash_equals($expectedPreviousComposerHash, $actualPreviousComposerHash)) {
        throw new RuntimeException('release.json composer_lock.previous_sha256 does not match the previous full archive.');
    }
    if (! is_string($actualCurrentComposerHash) || ! hash_equals($expectedCurrentComposerHash, $actualCurrentComposerHash)) {
        throw new RuntimeException('release.json composer_lock.current_sha256 does not match the target release tree.');
    }
}

/**
 * @param  list<string>  $repairFiles
 * @param  list<string>  $changedFiles
 */
function kaevReleaseAssertRepairFilesAreExceptional(array $repairFiles, array $changedFiles): void
{
    $changedLookup = array_fill_keys($changedFiles, true);
    foreach ($repairFiles as $repairFile) {
        if (isset($changedLookup[$repairFile])) {
            throw new RuntimeException('repair_files contains a file already changed by this release: '.$repairFile);
        }
    }
}

/**
 * @param  list<string>  $currentFiles
 * @return list<string>
 */
function kaevReleaseRepairFiles(mixed $configured, array $currentFiles): array
{
    if (! is_array($configured)) {
        throw new RuntimeException('release.json repair_files must be an array.');
    }

    $currentLookup = array_fill_keys($currentFiles, true);
    $repairFiles = [];

    foreach ($configured as $path) {
        if (! is_string($path) || ! kaevReleaseSafeRelativePath($path)) {
            throw new RuntimeException('release.json contains an invalid repair file path.');
        }
        if (! isset($currentLookup[$path])) {
            throw new RuntimeException('Repair file is missing from the release tree: '.$path);
        }

        $repairFiles[] = $path;
    }

    $repairFiles = array_values(array_unique($repairFiles));
    sort($repairFiles, SORT_STRING);

    return $repairFiles;
}

/** @return list<string> */
function kaevReleaseCollectFiles(string $root, array $ignoredRoots = []): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $ignored = array_map(
        static fn (string $path): string => rtrim(str_replace('\\', '/', (string) (realpath($path) ?: $path)), '/'),
        $ignoredRoots,
    );
    $files = [];
    $caseInsensitivePaths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        $absolute = str_replace('\\', '/', $file->getPathname());
        foreach ($ignored as $ignoredRoot) {
            if ($absolute === $ignoredRoot || str_starts_with($absolute, $ignoredRoot.'/')) {
                continue 2;
            }
        }
        $relative = substr($absolute, strlen($root) + 1);
        if (! is_string($relative) || ! kaevReleaseSafeRelativePath($relative)) {
            throw new RuntimeException('Unsafe release path: '.$absolute);
        }
        if (kaevReleaseExcluded($relative)) {
            continue;
        }
        if ($file->isLink()) {
            throw new RuntimeException('Release trees cannot contain symbolic links: '.$absolute);
        }
        if (! $file->isFile()) {
            continue;
        }
        if (preg_match('/\.(?:7z|gz|log|rar|tar|tgz|zip)$/i', $relative) === 1) {
            throw new RuntimeException('Unexpected archive or log file in release tree: '.$relative);
        }

        $pathKey = strtolower($relative);
        if (isset($caseInsensitivePaths[$pathKey])) {
            throw new RuntimeException('Case-insensitive release path collision: '.$relative);
        }
        $caseInsensitivePaths[$pathKey] = true;
        $files[] = $relative;
    }

    sort($files, SORT_STRING);

    return $files;
}

function kaevReleaseExcluded(string $path): bool
{
    $exact = [
        '.env',
        '.phpunit.cache',
        '.phpunit.result.cache',
        'bootstrap/kaevcms-public-path.php',
        'database/database.sqlite',
        'public/hot',
        'public/kaevcms-path.php',
    ];
    if (in_array($path, $exact, true)) {
        return true;
    }

    foreach ([
        '.git/',
        'dist/',
        'node_modules/',
        'playwright-report/',
        'public/storage/',
        'test-results/',
        'vendor/',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    if (str_starts_with($path, 'storage/')) {
        return basename($path) !== '.gitignore';
    }
    if (str_starts_with($path, 'bootstrap/cache/')) {
        return basename($path) !== '.gitignore';
    }
    if (str_starts_with($path, 'public/uploads/')) {
        return ! in_array(basename($path), ['.gitignore', '.gitkeep', '.htaccess'], true);
    }

    return false;
}

/** @param list<string> $files */
function kaevReleaseCreateZip(string $root, array $files, string $output, int $timestamp): void
{
    $directory = dirname($output);
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create ZIP output directory.');
    }

    $zip = new ZipArchive;
    if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create ZIP archive: '.$output);
    }

    try {
        foreach ($files as $relative) {
            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_file($absolute) || is_link($absolute)) {
                throw new RuntimeException('Release file is missing or unsafe: '.$relative);
            }
            if (! $zip->addFile($absolute, $relative)) {
                throw new RuntimeException('Unable to add file to ZIP: '.$relative);
            }
            $zip->setMtimeName($relative, $timestamp);
            $zip->setCompressionName($relative, ZipArchive::CM_DEFLATE, 9);
        }
    } finally {
        $zip->close();
    }
}

/** @param list<string>|null $expectedFiles */
function kaevReleaseValidateZip(string $archive, ?array $expectedFiles = null): void
{
    $zip = new ZipArchive;
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Unable to open generated ZIP: '.$archive);
    }

    $entries = [];
    $seen = [];
    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! is_string($name) || ! kaevReleaseSafeRelativePath(rtrim($name, '/')) || str_contains($name, '\\')) {
                throw new RuntimeException('Generated ZIP contains an unsafe entry.');
            }
            $nameKey = strtolower(rtrim($name, '/'));
            if (isset($seen[$nameKey])) {
                throw new RuntimeException('Generated ZIP contains a duplicate path: '.$name);
            }
            $seen[$nameKey] = true;
            if (kaevReleaseZipEntryIsSymlink($zip, $index)) {
                throw new RuntimeException('Generated ZIP contains a symbolic link: '.$name);
            }
            if (! str_ends_with($name, '/')) {
                $entries[] = $name;
            }
        }
    } finally {
        $zip->close();
    }

    sort($entries, SORT_STRING);
    if ($expectedFiles !== null) {
        $expected = $expectedFiles;
        sort($expected, SORT_STRING);
        if ($entries !== $expected) {
            throw new RuntimeException('Generated ZIP file list does not match the expected release tree.');
        }
    }
}

function kaevReleaseExtractZip(string $archive, string $destination): void
{
    if (! is_dir($destination) && ! mkdir($destination, 0775, true) && ! is_dir($destination)) {
        throw new RuntimeException('Unable to create ZIP extraction directory.');
    }

    $destinationRoot = realpath($destination);
    if (! is_string($destinationRoot)) {
        throw new RuntimeException('Unable to resolve ZIP extraction directory.');
    }

    $zip = new ZipArchive;
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Unable to open ZIP archive: '.$archive);
    }

    $seen = [];
    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! is_string($name) || str_contains($name, '\\')) {
                throw new RuntimeException('ZIP entry uses an unsafe path separator.');
            }
            $trimmed = rtrim($name, '/');
            if ($trimmed !== '' && ! kaevReleaseSafeRelativePath($trimmed)) {
                throw new RuntimeException('ZIP archive contains an unsafe entry: '.$name);
            }
            $nameKey = strtolower($trimmed);
            if ($trimmed !== '' && isset($seen[$nameKey])) {
                throw new RuntimeException('ZIP archive contains a duplicate path: '.$name);
            }
            if ($trimmed !== '') {
                $seen[$nameKey] = true;
            }
            if (kaevReleaseZipEntryIsSymlink($zip, $index)) {
                throw new RuntimeException('ZIP archive contains a symbolic link: '.$name);
            }

            $target = $destinationRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $trimmed);
            if ($trimmed === '' || str_ends_with($name, '/')) {
                if ($trimmed !== '' && ! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
                    throw new RuntimeException('Unable to create extracted directory: '.$trimmed);
                }

                continue;
            }

            $parent = dirname($target);
            if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
                throw new RuntimeException('Unable to create extracted file directory: '.$trimmed);
            }
            $source = $zip->getStream($name);
            $targetStream = fopen($target, 'wb');
            if (! is_resource($source) || ! is_resource($targetStream)) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($targetStream)) {
                    fclose($targetStream);
                }
                throw new RuntimeException('Unable to extract ZIP entry: '.$name);
            }
            try {
                if (stream_copy_to_stream($source, $targetStream) === false) {
                    throw new RuntimeException('Unable to write extracted ZIP entry: '.$name);
                }
            } finally {
                fclose($source);
                fclose($targetStream);
            }
        }
    } finally {
        $zip->close();
    }
}

function kaevReleaseZipEntryIsSymlink(ZipArchive $zip, int $index): bool
{
    $operatingSystem = 0;
    $attributes = 0;
    if (! $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
        return false;
    }

    return (($attributes >> 16) & 0170000) === 0120000;
}

function kaevReleaseSafeRelativePath(string $path): bool
{
    if ($path === ''
        || str_contains($path, "\0")
        || str_contains($path, '\\')
        || str_contains($path, ':')
        || str_starts_with($path, '/')
        || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        return false;
    }

    $segments = explode('/', $path);

    return ! in_array('', $segments, true)
        && ! in_array('.', $segments, true)
        && ! in_array('..', $segments, true);
}

/**
 * @param  list<string>  $currentFiles
 * @param  list<string>  $previousFiles
 * @return list<string>
 */
function kaevReleaseChangedFiles(string $root, array $currentFiles, string $previousRoot, array $previousFiles): array
{
    $previousLookup = array_fill_keys($previousFiles, true);
    $changed = [];

    foreach ($currentFiles as $relative) {
        $current = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $previous = $previousRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! isset($previousLookup[$relative]) || ! is_file($previous) || hash_file('sha256', $current) !== hash_file('sha256', $previous)) {
            $changed[] = $relative;
        }
    }

    sort($changed, SORT_STRING);

    return $changed;
}

/** @param list<string> $removedFiles */
function kaevReleaseAssertDeletionsDeclared(string $root, string $version, array $removedFiles): void
{
    $history = kaevReleaseReadJson($root.'/deployment/updates/deletions.json');
    $declared = $history[$version] ?? null;
    if (! is_array($declared)) {
        throw new RuntimeException('deletions.json must contain an entry for the target version.');
    }

    foreach ($removedFiles as $relative) {
        $logical = str_starts_with($relative, 'public/')
            ? 'public/'.substr($relative, 7)
            : 'core/'.$relative;
        $covered = false;
        foreach ($declared as $deletion) {
            if (! is_string($deletion)) {
                continue;
            }
            if ($logical === $deletion || str_starts_with($logical, rtrim($deletion, '/').'/')) {
                $covered = true;
                break;
            }
        }
        if (! $covered) {
            throw new RuntimeException('Removed release file is missing from the target deletion history: '.$logical);
        }
    }
}

function kaevReleaseApplyDeletions(string $root, string $version, string $targetRoot): void
{
    $history = kaevReleaseReadJson($root.'/deployment/updates/deletions.json');
    $deletions = $history[$version] ?? [];
    if (! is_array($deletions)) {
        throw new RuntimeException('Invalid target deletion history.');
    }

    foreach ($deletions as $logical) {
        if (! is_string($logical)) {
            throw new RuntimeException('Invalid deletion path type.');
        }
        $relative = kaevReleaseLogicalToRelative($logical);
        $target = $targetRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_dir($target) && ! is_link($target)) {
            kaevReleaseRemoveTree($target);
        } elseif (file_exists($target) || is_link($target)) {
            if (! unlink($target)) {
                throw new RuntimeException('Unable to apply release deletion: '.$logical);
            }
        }
    }
}

function kaevReleaseLogicalToRelative(string $logical): string
{
    if (str_starts_with($logical, 'core/')) {
        $relative = substr($logical, 5);
    } elseif (str_starts_with($logical, 'public/')) {
        $relative = 'public/'.substr($logical, 7);
    } else {
        throw new RuntimeException('Deletion path must start with core/ or public/: '.$logical);
    }
    if (! kaevReleaseSafeRelativePath($relative)) {
        throw new RuntimeException('Unsafe deletion path: '.$logical);
    }

    return $relative;
}

/** @param list<string> $files */
function kaevReleaseCopyFiles(string $sourceRoot, array $files, string $targetRoot): void
{
    foreach ($files as $relative) {
        $source = $sourceRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $target = $targetRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($target);
        if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
            throw new RuntimeException('Unable to create release comparison directory.');
        }
        if (! copy($source, $target)) {
            throw new RuntimeException('Unable to copy release comparison file: '.$relative);
        }
    }
}

/** @param list<string> $expectedFiles */
function kaevReleaseAssertTreesMatch(string $expectedRoot, array $expectedFiles, string $actualRoot): void
{
    $actualFiles = kaevReleaseCollectFiles($actualRoot);
    if ($actualFiles !== $expectedFiles) {
        $missing = array_values(array_diff($expectedFiles, $actualFiles));
        $unexpected = array_values(array_diff($actualFiles, $expectedFiles));
        throw new RuntimeException('Patch overlay file list mismatch. Missing: '.implode(', ', $missing).'; unexpected: '.implode(', ', $unexpected));
    }

    foreach ($expectedFiles as $relative) {
        $expected = $expectedRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $actual = $actualRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (hash_file('sha256', $expected) !== hash_file('sha256', $actual)) {
            throw new RuntimeException('Patch overlay content mismatch: '.$relative);
        }
    }
}

/** @param list<string> $files */
function kaevReleaseAssertRequiredFiles(string $root, array $files): void
{
    $manifest = kaevReleaseReadJson($root.'/deployment/release-files.json');
    $required = $manifest['required_files'] ?? null;
    if (! is_array($required)) {
        throw new RuntimeException('deployment/release-files.json is invalid.');
    }
    $lookup = array_fill_keys($files, true);
    foreach ($required as $requiredFile) {
        if (! is_string($requiredFile) || ! isset($lookup[$requiredFile])) {
            throw new RuntimeException('Required release file is missing from the full archive: '.(string) $requiredFile);
        }
    }
}

/** @param list<string> $command */
function kaevReleaseRunCommand(array $command, string $workingDirectory): void
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $workingDirectory);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start release command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (is_string($stdout) && $stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($exitCode !== 0) {
        throw new RuntimeException(trim((string) $stderr) !== '' ? trim((string) $stderr) : 'Release command failed with exit code '.$exitCode.'.');
    }
}

/** @return array<string, mixed> */
function kaevReleaseReadJson(string $path): array
{
    $contents = file_get_contents($path);
    if (! is_string($contents)) {
        throw new RuntimeException('Unable to read JSON file: '.$path);
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('JSON root must be an object: '.$path);
    }

    return $decoded;
}

function kaevReleaseRequireSha256(mixed $value, string $field): string
{
    if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
        throw new RuntimeException('release.json contains an invalid '.$field.'.');
    }

    return $value;
}

function kaevReleaseRequireVersion(mixed $value, string $field): string
{
    if (! is_string($value) || preg_match('/^\d+\.\d+\.\d+$/', $value) !== 1) {
        throw new RuntimeException('release.json contains an invalid '.$field.'.');
    }

    return $value;
}

function kaevReleaseAbsolutePath(string $path): string
{
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
        return $path;
    }

    return getcwd().DIRECTORY_SEPARATOR.$path;
}

function kaevReleaseTemporaryDirectory(string $prefix): string
{
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(8));
    if (! mkdir($path, 0700, true) && ! is_dir($path)) {
        throw new RuntimeException('Unable to create a temporary release directory.');
    }

    return $path;
}

function kaevReleaseRemoveTree(string $path): void
{
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
}
