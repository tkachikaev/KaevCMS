<?php

declare(strict_types=1);

require dirname(__DIR__).'/build-release.php';

$projectRoot = dirname(__DIR__, 3);
$temporaryRoot = kaevReleaseTemporaryDirectory('kaevcms-release-regression-');

try {
    $targetRoot = $temporaryRoot.'/target';
    $previousRoot = $temporaryRoot.'/previous';
    $previousArchive = $temporaryRoot.'/previous-full.zip';
    $firstOutput = $temporaryRoot.'/first';
    $secondOutput = $temporaryRoot.'/second';

    $projectFiles = kaevReleaseCollectFiles($projectRoot, [$projectRoot.'/dist']);
    kaevReleaseCopyFiles($projectRoot, $projectFiles, $targetRoot);

    // These files represent local runtime state and must never leak into release artifacts.
    writeFixture($targetRoot.'/.env', "APP_KEY=secret\n");
    writeFixture($targetRoot.'/storage/logs/runtime.log', "secret runtime log\n");
    writeFixture($targetRoot.'/public/uploads/account-avatars/user.webp', 'runtime-avatar');
    writeFixture($targetRoot.'/node_modules/example/package.json', "{}\n");
    writeFixture($targetRoot.'/test-results/failure.txt', "failure\n");
    writeFixture($targetRoot.'/.claude/launch.json', "{}\n");

    $targetRelease = readFixtureJson($targetRoot.'/release.json');
    $targetVersion = requiredFixtureVersion($targetRelease['version'] ?? null);
    $previousVersion = requiredFixtureVersion($targetRelease['previous_version'] ?? null);
    $currentApply = (string) ($targetRelease['apply_script'] ?? '');
    $previousApply = (string) ($targetRelease['previous_apply_script'] ?? '');

    $targetFiles = kaevReleaseCollectFiles($targetRoot);
    kaevReleaseCopyFiles($targetRoot, $targetFiles, $previousRoot);
    writeFixture($targetRoot.'/docs/release-builder-target-only.txt', "target-only fixture\n");
    @unlink($previousRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, $currentApply));
    writeFixture(
        $previousRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, $previousApply),
        "#requires -Version 5.1\nWrite-Host 'Synthetic previous apply script.'\n",
    );

    $previousApplyHash = hash_file(
        'sha256',
        $previousRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, $previousApply),
    );
    if (! is_string($previousApplyHash)) {
        throw new RuntimeException('Unable to hash synthetic previous apply script.');
    }
    $targetRelease['previous_apply_sha256'] = $previousApplyHash;
    // The fixture's previous tree is synthetic, not the published predecessor.
    // Give it a distinct valid lock file and bind metadata to those actual bytes
    // so dependency-changing releases exercise the patch path as well.
    $previousLock = file_get_contents($previousRoot.'/composer.lock');
    if (! is_string($previousLock)) {
        throw new RuntimeException('Unable to read synthetic previous dependency lock.');
    }
    writeFixture($previousRoot.'/composer.lock', $previousLock."\n");
    $previousLockHash = hash_file('sha256', $previousRoot.'/composer.lock');
    if (! is_string($previousLockHash)) {
        throw new RuntimeException('Unable to hash synthetic previous dependency lock.');
    }
    $targetRelease['composer_lock']['previous_sha256'] = $previousLockHash;
    writeFixtureJson($targetRoot.'/release.json', $targetRelease);

    $syntheticRelease = $targetRelease;
    $syntheticRelease['version'] = $previousVersion;
    $syntheticRelease['previous_version'] = syntheticOlderVersion($previousVersion);
    $syntheticRelease['apply_script'] = $previousApply;
    $syntheticRelease['previous_apply_script'] = 'deployment/windows/apply-'.syntheticOlderVersion($previousVersion).'.ps1';
    $syntheticRelease['previous_apply_sha256'] = str_repeat('0', 64);
    $syntheticRelease['composer_lock']['current_sha256'] = $previousLockHash;
    writeFixtureJson($previousRoot.'/release.json', $syntheticRelease);
    writeFixture($previousRoot.'/VERSION', $previousVersion."\n");

    $manifest = readFixtureJson($previousRoot.'/deployment/release-files.json');
    $requiredFiles = $manifest['required_files'] ?? [];
    if (! is_array($requiredFiles)) {
        throw new RuntimeException('Synthetic release manifest is invalid.');
    }
    $requiredFiles = array_values(array_filter(
        $requiredFiles,
        static fn (mixed $file): bool => is_string($file) && $file !== $currentApply,
    ));
    $requiredFiles[] = $previousApply;
    sort($requiredFiles, SORT_STRING);
    $manifest['required_files'] = array_values(array_unique($requiredFiles));
    writeFixtureJson($previousRoot.'/deployment/release-files.json', $manifest);

    $deletions = readFixtureJson($previousRoot.'/deployment/updates/deletions.json');
    unset($deletions[$targetVersion]);
    writeFixtureJson($previousRoot.'/deployment/updates/deletions.json', $deletions);

    $previousFiles = kaevReleaseCollectFiles($previousRoot);
    $releaseTimestamp = strtotime((string) ($syntheticRelease['released_at'] ?? '2026-01-01').' 12:00:00 UTC');
    if ($releaseTimestamp === false) {
        throw new RuntimeException('Synthetic release date is invalid.');
    }
    kaevReleaseCreateZip($previousRoot, $previousFiles, $previousArchive, $releaseTimestamp);

    $vendorRoot = $temporaryRoot.'/production-vendor';
    writeFixtureVendor($vendorRoot, $targetRoot.'/composer.lock');

    $first = buildFixtureRelease($targetRoot, $previousArchive, $firstOutput, $vendorRoot);
    $second = buildFixtureRelease($targetRoot, $previousArchive, $secondOutput, $vendorRoot);

    // The cumulative Web Update carries the production vendor tree for its composer.lock.
    $cumulativeZip = new ZipArchive;
    assertFixture($cumulativeZip->open($first['cumulative']) === true, 'The cumulative package must be readable.');
    $cumulativeManifest = json_decode((string) $cumulativeZip->getFromName('kaevcms-update.json'), true);
    $bundleBytes = $cumulativeZip->getFromName('payload/core/deployment/updates/dependency-bundle/vendor.zip');
    $cumulativeZip->close();
    assertFixture(is_string($bundleBytes) && $bundleBytes !== '', 'The cumulative package must include the dependency bundle.');
    $dependencies = is_array($cumulativeManifest) ? ($cumulativeManifest['dependencies'] ?? null) : null;
    assertFixture(is_array($dependencies), 'The cumulative manifest must describe the dependency bundle.');
    assertFixture(($dependencies['composer_lock_sha256'] ?? null) === hash_file('sha256', $targetRoot.'/composer.lock'), 'The dependency bundle must be bound to the packaged composer.lock.');
    assertFixture(($dependencies['sha256'] ?? null) === hash('sha256', (string) $bundleBytes), 'The dependency bundle checksum must match its bytes.');
    assertFixture(($dependencies['platform']['php_version_id'] ?? null) === 80300, 'PHP requirement must come from platform_check.php.');
    $requiredExtensions = (array) ($dependencies['platform']['extensions'] ?? []);
    assertFixture(in_array('mbstring', $requiredExtensions, true), 'Extension checks from platform_check.php must be kept.');
    assertFixture(in_array('openssl', $requiredExtensions, true), 'ext-* requirements of locked packages must be collected.');
    assertFixture(! in_array('ctype', $requiredExtensions, true), 'Extensions provided by a locked polyfill must not be required.');
    assertFixture(! in_array('deployment/updates/dependency-bundle/vendor.zip', fixtureZipEntries($first['full']), true), 'The full release must stay without vendor.');

    $mismatchedVendor = $temporaryRoot.'/mismatched-vendor';
    writeFixtureVendor($mismatchedVendor, $targetRoot.'/composer.lock', 'v0.0.0-not-locked');
    assertFixtureBuildFails(
        $targetRoot,
        $previousArchive,
        $temporaryRoot.'/mismatched-vendor-output',
        'The vendor directory does not match composer.lock',
        $mismatchedVendor,
    );

    foreach (['full', 'patch', 'cumulative', 'checksums'] as $artifact) {
        assertFixture(isset($first[$artifact]) && is_file($first[$artifact]), 'Missing first '.$artifact.' artifact.');
        assertFixture(isset($second[$artifact]) && is_file($second[$artifact]), 'Missing second '.$artifact.' artifact.');
        assertFixture(
            hash_file('sha256', $first[$artifact]) === hash_file('sha256', $second[$artifact]),
            'Release artifacts must be deterministic: '.$artifact,
        );
    }

    $fullEntries = fixtureZipEntries($first['full']);
    foreach ([
        '.env',
        'storage/logs/runtime.log',
        'public/uploads/account-avatars/user.webp',
        'node_modules/example/package.json',
        'test-results/failure.txt',
        '.claude/launch.json',
    ] as $forbidden) {
        assertFixture(! in_array($forbidden, $fullEntries, true), 'Runtime file leaked into full release: '.$forbidden);
    }
    assertFixture(in_array('public/uploads/.htaccess', $fullEntries, true), 'Upload protection must remain in the full release.');
    assertFixture(in_array('deployment/release/build-release.php', $fullEntries, true), 'Unified release builder must be shipped.');
    assertFixture(in_array('docs/release-builder-target-only.txt', $fullEntries, true), 'Target-only file must enter the full release.');
    assertFixture(in_array('docs/release-builder-target-only.txt', fixtureZipEntries($first['patch']), true), 'Target-only file must enter the direct patch.');
    assertFixture(in_array('composer.lock', fixtureZipEntries($first['patch']), true), 'Changed dependency lock must enter the direct patch.');

    $checksumLines = file($first['checksums'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $expectedArtifacts = isset($first['bridge']) ? 4 : 3;
    assertFixture(is_array($checksumLines) && count($checksumLines) === $expectedArtifacts, 'Checksum file must list every release artifact.');

    // Bridge for sites whose updater cannot replace dependencies: current code, no lock, no vendor.
    $bridgeTarget = $previousVersion.'-bridge';
    $bridgePath = $temporaryRoot.'/bridge/bridge.zip';
    kaevReleaseRunCommand([
        PHP_BINARY,
        $targetRoot.'/deployment/updates/build-package.php',
        '--root='.$targetRoot,
        '--output='.$bridgePath,
        '--minimum='.syntheticOlderVersion(syntheticOlderVersion($previousVersion)),
        '--maximum='.syntheticOlderVersion($previousVersion),
        '--target='.$bridgeTarget,
        '--delete-file='.$targetRoot.'/deployment/updates/deletions.json',
        '--bridge',
    ], $targetRoot);
    $bridgeZip = new ZipArchive;
    assertFixture($bridgeZip->open($bridgePath) === true, 'The bridge package must be readable.');
    $bridgeManifest = json_decode((string) $bridgeZip->getFromName('kaevcms-update.json'), true);
    $bridgeVersion = $bridgeZip->getFromName('payload/core/VERSION');
    $bridgeEntries = [];
    for ($index = 0; $index < $bridgeZip->numFiles; $index++) {
        $bridgeEntries[] = (string) $bridgeZip->getNameIndex($index);
    }
    $bridgeZip->close();
    assertFixture(is_array($bridgeManifest) && ($bridgeManifest['target_version'] ?? null) === $bridgeTarget, 'The bridge must target the bridge version.');
    assertFixture($bridgeVersion === $bridgeTarget."\n", 'The bridge VERSION file must carry the bridge version.');
    assertFixture(! in_array('payload/core/composer.lock', $bridgeEntries, true), 'The bridge must not change composer.lock.');
    assertFixture(! isset($bridgeManifest['dependencies']), 'The bridge must not carry a dependency bundle.');
    assertFixture(in_array('payload/core/app/Services/Updates/UpdateDependencyBundle.php', $bridgeEntries, true), 'The bridge must install the dependency-aware updater.');
    $bridgeDeclared = array_column((array) ($bridgeManifest['files'] ?? []), 'source');
    foreach ($bridgeEntries as $entry) {
        assertFixture($entry === 'kaevcms-update.json' || in_array($entry, $bridgeDeclared, true), 'Older updaters reject undeclared bridge entries: '.$entry);
    }

    $validTargetRelease = readFixtureJson($targetRoot.'/release.json');

    $invalidPreviousApplyHash = $validTargetRelease;
    $invalidPreviousApplyHash['previous_apply_sha256'] = str_repeat('0', 64);
    writeFixtureJson($targetRoot.'/release.json', $invalidPreviousApplyHash);
    assertFixtureBuildFails(
        $targetRoot,
        $previousArchive,
        $temporaryRoot.'/invalid-previous-apply-hash',
        'previous_apply_sha256 does not match the previous full archive',
    );

    $invalidComposerHash = $validTargetRelease;
    $invalidComposerHash['composer_lock']['current_sha256'] = str_repeat('0', 64);
    writeFixtureJson($targetRoot.'/release.json', $invalidComposerHash);
    assertFixtureBuildFails(
        $targetRoot,
        $previousArchive,
        $temporaryRoot.'/invalid-current-composer-hash',
        'composer_lock.current_sha256 does not match the target release tree',
    );

    $invalidPreviousComposerHash = $validTargetRelease;
    $invalidPreviousComposerHash['composer_lock']['previous_sha256'] = str_repeat('0', 64);
    writeFixtureJson($targetRoot.'/release.json', $invalidPreviousComposerHash);
    assertFixtureBuildFails(
        $targetRoot,
        $previousArchive,
        $temporaryRoot.'/invalid-previous-composer-hash',
        'composer_lock.previous_sha256 does not match the previous full archive',
    );

    $invalidApplyScript = $validTargetRelease;
    $invalidApplyScript['apply_script'] = 'deployment/windows/apply-0.0.0.ps1';
    writeFixtureJson($targetRoot.'/release.json', $invalidApplyScript);
    assertFixtureBuildFails(
        $targetRoot,
        $previousArchive,
        $temporaryRoot.'/invalid-apply-script',
        'apply_script does not match the target version',
    );

    $redundantRepairFile = $validTargetRelease;
    $redundantRepairFile['repair_files'] = ['docs/release-builder-target-only.txt'];
    writeFixtureJson($targetRoot.'/release.json', $redundantRepairFile);
    assertFixtureBuildFails(
        $targetRoot,
        $previousArchive,
        $temporaryRoot.'/redundant-repair-file',
        'repair_files contains a file already changed by this release',
    );

    writeFixtureJson($targetRoot.'/release.json', $validTargetRelease);

    fwrite(STDOUT, "Unified release builder regression checks completed successfully.\n");
} finally {
    kaevReleaseRemoveTree($temporaryRoot);
}

/**
 * A minimal production vendor tree whose installed.json matches the given composer.lock.
 */
function writeFixtureVendor(string $vendorRoot, string $composerLock, ?string $versionOverride = null): void
{
    $lock = readFixtureJson($composerLock);
    $packages = [];
    foreach ((array) ($lock['packages'] ?? []) as $package) {
        if (is_array($package) && is_string($package['name'] ?? null)) {
            $packages[] = ['name' => $package['name'], 'version' => $versionOverride ?? (string) ($package['version'] ?? '')];
        }
    }
    writeFixtureJson($vendorRoot.'/composer/installed.json', ['packages' => $packages, 'dev' => false, 'dev-package-names' => []]);
    writeFixture($vendorRoot.'/autoload.php', "<?php\nreturn require __DIR__.'/composer/autoload_real.php';\n");
    writeFixture($vendorRoot.'/composer/autoload_real.php', "<?php\n");
    writeFixture($vendorRoot.'/composer/platform_check.php', "<?php\n\$issues = array();\nif (!(PHP_VERSION_ID >= 80300)) {\n    \$issues[] = 'php';\n}\nif (!extension_loaded('mbstring')) {\n    \$issues[] = 'mbstring';\n}\n");
    writeFixture($vendorRoot.'/acme/library/src/Library.php', "<?php\n");
}

/** @return array{full:string,patch:string,cumulative:string,checksums:string} */
function buildFixtureRelease(string $root, string $previousArchive, string $outputDirectory, ?string $vendor = null): array
{
    $options = [
        'root' => $root,
        'previous' => $previousArchive,
        'output-dir' => $outputDirectory,
    ];
    if ($vendor !== null) {
        $options['vendor'] = $vendor;
    }
    $artifacts = kaevReleaseBuild($options);

    $release = readFixtureJson($root.'/release.json');
    $version = requiredFixtureVersion($release['version'] ?? null);
    $previousVersion = requiredFixtureVersion($release['previous_version'] ?? null);
    $base = requiredFixtureVersion($release['cumulative_base_version'] ?? null);
    $range = $base === $previousVersion ? $base : $base.'-'.$previousVersion;
    $expected = [
        'full' => $outputDirectory.'/KaevCMS-'.$version.'-full.zip',
        'patch' => $outputDirectory.'/KaevCMS-'.$previousVersion.'-to-'.$version.'-patch.zip',
        'cumulative' => $outputDirectory.'/KaevCMS-cumulative-update-'.$range.'-to-'.$version.'.zip',
        'checksums' => $outputDirectory.'/KaevCMS-'.$version.'-SHA256SUMS.txt',
    ];
    if (version_compare($previousVersion, KAEV_RELEASE_LAST_LEGACY_UPDATER, '>')) {
        $expected['bridge'] = $outputDirectory.'/KaevCMS-bridge-update-'.$base.'-'.KAEV_RELEASE_LAST_LEGACY_UPDATER.'-for-'.$version.'.zip';
    }
    assertFixture(
        array_keys($artifacts) === array_keys($expected),
        'Unified release builder returned unexpected artifact labels.',
    );
    foreach ($expected as $artifact => $expectedPath) {
        assertFixture(
            fixtureCanonicalPath($artifacts[$artifact]) === fixtureCanonicalPath($expectedPath),
            'Unified release builder returned an unexpected path for '.$artifact.'.',
        );
    }

    return $artifacts;
}

function assertFixtureBuildFails(
    string $root,
    string $previousArchive,
    string $outputDirectory,
    string $expectedMessage,
    ?string $vendor = null,
): void {
    try {
        kaevReleaseBuild(array_filter([
            'root' => $root,
            'previous' => $previousArchive,
            'output-dir' => $outputDirectory,
            'vendor' => $vendor,
        ], static fn (?string $value): bool => $value !== null));
    } catch (RuntimeException $exception) {
        assertFixture(
            str_contains($exception->getMessage(), $expectedMessage),
            'Unexpected release builder failure: '.$exception->getMessage(),
        );

        return;
    }

    throw new RuntimeException('Release builder accepted invalid metadata: '.$expectedMessage);
}

function fixtureCanonicalPath(string $path): string
{
    $resolved = realpath($path);
    $canonical = str_replace('\\', '/', is_string($resolved) ? $resolved : $path);

    return rtrim($canonical, '/');
}

/** @return list<string> */
function fixtureZipEntries(string $archive): array
{
    $zip = new ZipArchive;
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Unable to inspect fixture ZIP.');
    }
    $entries = [];
    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && ! str_ends_with($name, '/')) {
                $entries[] = $name;
            }
        }
    } finally {
        $zip->close();
    }
    sort($entries, SORT_STRING);

    return $entries;
}

function writeFixture(string $path, string $contents): void
{
    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create fixture directory.');
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Unable to write fixture file.');
    }
}

/** @param array<string, mixed> $value */
function writeFixtureJson(string $path, array $value): void
{
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    writeFixture($path, $encoded."\n");
}

/** @return array<string, mixed> */
function readFixtureJson(string $path): array
{
    $contents = file_get_contents($path);
    if (! is_string($contents)) {
        throw new RuntimeException('Unable to read fixture JSON.');
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Fixture JSON root must be an object.');
    }

    return $decoded;
}

function requiredFixtureVersion(mixed $value): string
{
    if (! is_string($value) || preg_match('/^\d+\.\d+\.\d+$/', $value) !== 1) {
        throw new RuntimeException('Fixture release version is invalid.');
    }

    return $value;
}

function syntheticOlderVersion(string $version): string
{
    [$major, $minor, $patch] = array_map('intval', explode('.', $version));
    if ($patch > 0) {
        return $major.'.'.$minor.'.'.($patch - 1);
    }
    if ($minor > 0) {
        return $major.'.'.($minor - 1).'.0';
    }

    return '0.0.0';
}

function assertFixture(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
