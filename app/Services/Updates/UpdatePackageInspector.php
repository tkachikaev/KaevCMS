<?php

namespace App\Services\Updates;

use Closure;
use RuntimeException;
use Throwable;
use ZipArchive;

final class UpdatePackageInspector
{
    /**
     * The vendor bundle is declared as an ordinary payload file so that Web Updaters which
     * predate dependency bundles still accept the package (they reject undeclared entries)
     * and merely copy the archive. This updater consumes it instead of installing it.
     */
    public const DEPENDENCY_BUNDLE_TARGET = 'core/deployment/updates/dependency-bundle/vendor.zip';

    public function __construct(
        private readonly UpdateInstallationLayout $layout,
        private readonly UpdatePathPolicy $pathPolicy,
        private readonly ?string $stagingRootOverride = null,
        private readonly ?Closure $chmodOverride = null,
    ) {}

    public function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function inspect(string $archivePath, string $currentVersion): InspectedUpdatePackage
    {
        if (preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $currentVersion) !== 1) {
            throw new RuntimeException(__('The installed KaevCMS version is invalid.'));
        }

        if (! $this->available()) {
            throw new RuntimeException(__('The PHP zip extension is required to inspect update packages.'));
        }

        if (! is_file($archivePath) || ! is_readable($archivePath)) {
            throw new RuntimeException(__('The uploaded update package is not readable.'));
        }

        $archiveSha256 = hash_file('sha256', $archivePath);
        if (! is_string($archiveSha256)) {
            throw new RuntimeException(__('Unable to calculate the update package checksum.'));
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($openResult !== true) {
            throw new RuntimeException(__('The uploaded file is not a readable ZIP archive.'));
        }

        $stagingRoot = $this->stagingRoot();
        $this->prepareStagingRoot($stagingRoot);
        $stagingPath = $stagingRoot.DIRECTORY_SEPARATOR.bin2hex(random_bytes(16));
        try {
            $this->validateArchiveEnvelope($zip);
            $manifest = $this->decodeManifest($zip);
            $validated = $this->validateManifest($zip, $manifest, $currentVersion);
            $this->extractPayload($zip, $validated['files'], $stagingPath);
            $dependencies = $this->dependencyPlan($zip, $validated, $stagingPath);
            $warnings = $dependencies === null ? [] : [
                __('This update replaces the Composer dependencies (:count files). The previous vendor directory is kept until the update completes.', ['count' => $dependencies['files']]),
            ];
        } catch (Throwable $exception) {
            $this->removeDirectory($stagingPath);
            $zip->close();

            throw $exception;
        }

        $zip->close();

        return new InspectedUpdatePackage(
            packageId: $validated['package_id'],
            name: $validated['name'],
            currentVersion: $currentVersion,
            targetVersion: $validated['target_version'],
            minimumVersion: $validated['minimum_version'],
            maximumVersion: $validated['maximum_version'],
            installationType: $this->layout->type(),
            manifest: $manifest,
            files: $validated['files'],
            delete: $validated['delete'],
            warnings: $warnings,
            migrate: $validated['migrate'],
            archivePath: $archivePath,
            archiveSha256: $archiveSha256,
            stagingPath: $stagingPath,
            dependencies: $dependencies,
        );
    }

    private function stagingRoot(): string
    {
        return $this->stagingRootOverride ?? storage_path('app/kaevcms/updates/staging');
    }

    private function prepareStagingRoot(string $stagingRoot): void
    {
        $this->assertStagingRootIsNotSymbolicLink($stagingRoot);

        $created = false;
        if (! is_dir($stagingRoot)) {
            if (! mkdir($stagingRoot, 0770, true) && ! is_dir($stagingRoot)) {
                throw new RuntimeException(__('Unable to create the update staging directory.'));
            }

            $created = true;
        }

        $this->assertStagingRootIsNotSymbolicLink($stagingRoot);

        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $stagingRoot);
            $permissions = fileperms($stagingRoot);
            if (! is_int($permissions)) {
                throw new RuntimeException(__('Unable to secure the update staging directory.'));
            }

            $needsNormalization = $created || (($permissions & 0007) !== 0);
            if ($needsNormalization && ! $this->changePermissions($stagingRoot, 02770)) {
                clearstatcache(true, $stagingRoot);
                $permissions = fileperms($stagingRoot);
                if (! is_int($permissions) || (($permissions & 0007) !== 0)) {
                    throw new RuntimeException(__('Unable to secure the update staging directory.'));
                }
            }
        }

        if (! is_writable($stagingRoot)) {
            throw new RuntimeException(__('The update staging directory is not writable by PHP-FPM.'));
        }
    }

    private function assertStagingRootIsNotSymbolicLink(string $stagingRoot): void
    {
        clearstatcache(true, $stagingRoot);

        if (is_link($stagingRoot)) {
            throw new RuntimeException(__('Unable to secure the update staging directory.'));
        }
    }

    private function changePermissions(string $path, int $permissions): bool
    {
        if ($this->chmodOverride !== null) {
            return (bool) ($this->chmodOverride)($path, $permissions);
        }

        return @chmod($path, $permissions);
    }

    private function validateArchiveEnvelope(ZipArchive $zip): void
    {
        $maximumFiles = max(1, (int) config('cms.updates.maximum_archive_files', 20000));
        $maximumBytes = max(1, (int) config('cms.updates.maximum_uncompressed_bytes', 1073741824));

        if ($zip->numFiles < 1 || $zip->numFiles > $maximumFiles) {
            throw new RuntimeException(__('The update archive contains an invalid number of files.'));
        }

        $seen = [];
        $uncompressedBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                throw new RuntimeException(__('The update archive contains an unreadable entry.'));
            }

            $name = $stat['name'];
            if (! $this->pathPolicy->isSafeArchivePath($name)) {
                throw new RuntimeException(__('Unsafe archive path: :path', ['path' => $name]));
            }

            $nameKey = strtolower($name);
            if (isset($seen[$nameKey])) {
                throw new RuntimeException(__('Duplicate archive path: :path', ['path' => $name]));
            }
            $seen[$nameKey] = true;

            if ($this->isSymbolicLink($zip, $index)) {
                throw new RuntimeException(__('Symbolic links are not allowed in update packages: :path', ['path' => $name]));
            }

            $uncompressedBytes += max(0, $stat['size']);
            if ($uncompressedBytes > $maximumBytes) {
                throw new RuntimeException(__('The uncompressed update package exceeds the configured safety limit.'));
            }
        }
    }

    /** @return array<string, mixed> */
    private function decodeManifest(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('kaevcms-update.json');
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException(__('The update package does not contain kaevcms-update.json.'));
        }

        if (strlen($contents) > 1048576) {
            throw new RuntimeException(__('The update manifest is too large.'));
        }

        $manifest = json_decode($contents, true);
        if (! is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(__('The update manifest is not valid JSON.'));
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     package_id: string,
     *     name: string,
     *     target_version: string,
     *     minimum_version: string,
     *     maximum_version: string,
     *     files: list<array{source: string, target: string, sha256: string, size: int}>,
     *     delete: list<string>,
     *     migrate: bool,
     *     dependencies: array{archive: string, sha256: string, size: int, files: int, bytes: int, composer_lock_sha256: string, php_version_id: int, extensions: list<string>}|null
     * }
     */
    private function validateManifest(ZipArchive $zip, array $manifest, string $currentVersion): array
    {
        if (($manifest['schema'] ?? null) !== 1) {
            throw new RuntimeException(__('This update manifest schema is not supported.'));
        }

        $packageId = $this->requiredString($manifest, 'package_id', 190);
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,189}\z/', $packageId) !== 1) {
            throw new RuntimeException(__('The update package identifier is invalid.'));
        }

        $targetVersion = $this->requiredVersion($manifest, 'target_version');
        $minimumVersion = $this->requiredVersion($manifest, 'minimum_version');
        $maximumVersion = $this->requiredVersion($manifest, 'maximum_version');
        $name = $this->optionalString($manifest, 'name', 190) ?? "KaevCMS {$targetVersion}";

        if (version_compare($minimumVersion, $maximumVersion, '>')) {
            throw new RuntimeException(__('The update package version range is invalid.'));
        }

        if (version_compare($targetVersion, $maximumVersion, '<=')) {
            throw new RuntimeException(__('The update target must be newer than the maximum supported source version.'));
        }

        if (version_compare($targetVersion, $currentVersion, '<=')) {
            throw new RuntimeException(__('The update target must be newer than the installed version.'));
        }

        if (version_compare($currentVersion, $minimumVersion, '<')
            || version_compare($currentVersion, $maximumVersion, '>')) {
            throw new RuntimeException(__('This package supports KaevCMS :minimum through :maximum; the installed version is :current.', ['minimum' => $minimumVersion, 'maximum' => $maximumVersion, 'current' => $currentVersion]));
        }

        $this->validateRequirements($manifest);

        $rawFiles = $manifest['files'] ?? null;
        if (! is_array($rawFiles) || $rawFiles === []) {
            throw new RuntimeException(__('The update manifest does not contain files.'));
        }

        $files = [];
        $sourcePaths = [];
        $targetPaths = [];
        $versionTargetFound = false;

        foreach ($rawFiles as $rawFile) {
            if (! is_array($rawFile)) {
                throw new RuntimeException(__('The update manifest contains an invalid file entry.'));
            }

            $source = $this->requiredString($rawFile, 'source', 500);
            $target = $this->requiredString($rawFile, 'target', 500);
            $sha256 = strtolower($this->requiredString($rawFile, 'sha256', 64));
            $size = $rawFile['size'] ?? null;

            if (! $this->pathPolicy->isSafePayloadSource($source)) {
                throw new RuntimeException(__('Unsafe update payload source: :source', ['source' => $source]));
            }

            if (! $this->pathPolicy->isSafeTarget($target)) {
                throw new RuntimeException(__('Unsafe update target: :target', ['target' => $target]));
            }

            if ($source !== 'payload/'.$target) {
                throw new RuntimeException(__('Update payload sources must match their logical targets: :target', ['target' => $target]));
            }

            if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1 || ! is_int($size) || $size < 0) {
                throw new RuntimeException(__('Invalid integrity metadata for update target: :target', ['target' => $target]));
            }

            $sourceKey = strtolower($source);
            $targetKey = strtolower($target);
            if (isset($sourcePaths[$sourceKey]) || isset($targetPaths[$targetKey])) {
                throw new RuntimeException(__('Duplicate update file mapping: :target', ['target' => $target]));
            }
            foreach (array_keys($targetPaths) as $existingTarget) {
                if (str_starts_with($targetKey.'/', $existingTarget.'/')
                    || str_starts_with($existingTarget.'/', $targetKey.'/')) {
                    throw new RuntimeException(__('Conflicting update file targets: :target', ['target' => $target]));
                }
            }

            $sourcePaths[$sourceKey] = true;
            $targetPaths[$targetKey] = true;

            $stat = $zip->statName($source);
            if ($stat === false || $stat['size'] !== $size) {
                throw new RuntimeException(__('The update payload size does not match the manifest: :source', ['source' => $source]));
            }

            $actualHash = $this->hashArchiveEntry($zip, $source);
            if (! hash_equals($sha256, $actualHash)) {
                throw new RuntimeException(__('The update payload checksum does not match the manifest: :source', ['source' => $source]));
            }

            if ($target === 'core/VERSION') {
                $versionContents = $zip->getFromName($source);
                if (! is_string($versionContents) || trim($versionContents) !== $targetVersion) {
                    throw new RuntimeException(__('The VERSION file in the update payload does not match target_version.'));
                }
                $versionTargetFound = true;
            }

            $files[] = [
                'source' => $source,
                'target' => $target,
                'sha256' => $sha256,
                'size' => $size,
            ];
        }

        if (! $versionTargetFound) {
            throw new RuntimeException(__('The update package must contain core/VERSION.'));
        }

        $this->validateDeclaredEntries($zip, array_keys($sourcePaths));
        $dependencyBundle = $this->validateDependencySection($manifest, $files);
        // The bundle is consumed by the dependency swap, never installed as a project file.
        $files = array_values(array_filter(
            $files,
            static fn (array $file): bool => $file['target'] !== self::DEPENDENCY_BUNDLE_TARGET,
        ));

        $delete = [];
        $deletePaths = [];
        $rawDelete = $manifest['delete'] ?? [];
        if (! is_array($rawDelete)) {
            throw new RuntimeException(__('The update deletion list is invalid.'));
        }

        foreach ($rawDelete as $target) {
            if (! is_string($target) || ! $this->pathPolicy->isSafeTarget($target)) {
                throw new RuntimeException(__('The update deletion list contains an unsafe path.'));
            }

            $targetKey = strtolower($target);
            if (isset($targetPaths[$targetKey])) {
                throw new RuntimeException(__('A path cannot be replaced and deleted by the same update: :target', ['target' => $target]));
            }

            foreach (array_keys($targetPaths) as $fileTarget) {
                if (str_starts_with($targetKey.'/', $fileTarget.'/')) {
                    throw new RuntimeException(__('An update cannot replace a file and delete a path below it: :target', ['target' => $target]));
                }
            }

            if (isset($deletePaths[$targetKey])) {
                throw new RuntimeException(__('Duplicate update deletion path: :target', ['target' => $target]));
            }

            $deletePaths[$targetKey] = true;
            $delete[] = $target;
        }

        $this->validateChangelog($manifest);

        return [
            'package_id' => $packageId,
            'name' => $name,
            'target_version' => $targetVersion,
            'minimum_version' => $minimumVersion,
            'maximum_version' => $maximumVersion,
            'files' => $files,
            'delete' => $delete,
            'migrate' => $this->manifestBoolean($manifest, 'migrate', true),
            'dependencies' => $dependencyBundle,
        ];
    }

    /**
     * Validates the optional production vendor bundle. It must describe the composer.lock
     * shipped in the same package, so a bundle can never be paired with other dependencies.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<array{source: string, target: string, sha256: string, size: int}>  $files
     * @return array{archive: string, sha256: string, size: int, files: int, bytes: int, composer_lock_sha256: string, php_version_id: int, extensions: list<string>}|null
     */
    private function validateDependencySection(array $manifest, array $files): ?array
    {
        $section = $manifest['dependencies'] ?? null;
        if ($section === null) {
            return null;
        }

        $archive = 'payload/'.self::DEPENDENCY_BUNDLE_TARGET;
        if (! is_array($section) || ($section['schema'] ?? null) !== 1 || ($section['archive'] ?? null) !== $archive) {
            throw new RuntimeException(__('The update dependency bundle section is invalid.'));
        }

        $sha256 = strtolower((string) ($section['sha256'] ?? ''));
        $lockSha256 = strtolower((string) ($section['composer_lock_sha256'] ?? ''));
        $size = $section['size'] ?? null;
        $fileCount = $section['files'] ?? null;
        $bytes = $section['bytes'] ?? null;
        $platform = $section['platform'] ?? null;
        $phpVersionId = is_array($platform) ? ($platform['php_version_id'] ?? null) : null;
        $extensions = is_array($platform) ? ($platform['extensions'] ?? null) : null;
        if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $lockSha256) !== 1
            || ! is_int($size) || $size < 1
            || ! is_int($fileCount) || $fileCount < 1 || $fileCount > max(1, (int) config('cms.updates.maximum_dependency_files', 60000))
            || ! is_int($bytes) || $bytes < 1 || $bytes > max(1, (int) config('cms.updates.maximum_uncompressed_bytes', 1073741824))
            || ! is_int($phpVersionId) || $phpVersionId < 80300
            || ! is_array($extensions)) {
            throw new RuntimeException(__('The update dependency bundle section is invalid.'));
        }

        foreach ($extensions as $extension) {
            if (! is_string($extension) || preg_match('/\A[a-z0-9_-]+\z/i', $extension) !== 1) {
                throw new RuntimeException(__('The update dependency bundle section is invalid.'));
            }
        }

        $packagedLock = null;
        $bundleFile = null;
        foreach ($files as $file) {
            if ($file['target'] === 'core/composer.lock') {
                $packagedLock = $file['sha256'];
            }
            if ($file['target'] === self::DEPENDENCY_BUNDLE_TARGET) {
                $bundleFile = $file;
            }
        }
        if ($packagedLock === null || ! hash_equals($packagedLock, $lockSha256)) {
            throw new RuntimeException(__('The dependency bundle was built for a different composer.lock than the one in this package.'));
        }

        // The payload loop already verified the entry against its own file checksum.
        if ($bundleFile === null || ! hash_equals($bundleFile['sha256'], $sha256) || $bundleFile['size'] !== $size) {
            throw new RuntimeException(__('The dependency bundle checksum does not match the manifest.'));
        }

        return [
            'archive' => $archive,
            'sha256' => $sha256,
            'size' => $size,
            'files' => $fileCount,
            'bytes' => $bytes,
            'composer_lock_sha256' => $lockSha256,
            'php_version_id' => $phpVersionId,
            'extensions' => array_values($extensions),
        ];
    }

    /** @param list<string> $declaredSources */
    private function validateDeclaredEntries(ZipArchive $zip, array $declaredSources): void
    {
        $allowed = array_fill_keys(array_map('strtolower', $declaredSources), true);
        $allowed['kaevcms-update.json'] = true;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                throw new RuntimeException(__('The update archive contains an unreadable entry.'));
            }

            $name = $stat['name'];
            if (str_ends_with($name, '/')) {
                continue;
            }

            if (! isset($allowed[strtolower($name)])) {
                throw new RuntimeException(__('The update archive contains an undeclared file: :path', ['path' => $name]));
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateChangelog(array $manifest): void
    {
        $changelog = $manifest['changelog'] ?? [];
        if (! is_array($changelog) || count($changelog) > 200) {
            throw new RuntimeException(__('The update changelog is invalid.'));
        }

        foreach ($changelog as $entry) {
            if (! is_string($entry) || trim($entry) === '' || mb_strlen($entry) > 1000) {
                throw new RuntimeException(__('The update changelog is invalid.'));
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateRequirements(array $manifest): void
    {
        $requirements = $manifest['requires'] ?? [];
        if (! is_array($requirements)) {
            throw new RuntimeException(__('The update requirements section is invalid.'));
        }

        $php = $requirements['php'] ?? '8.3.0';
        if (! is_string($php) || preg_match('/\A\d+\.\d+\.\d+\z/', $php) !== 1) {
            throw new RuntimeException(__('The update package contains an invalid PHP requirement.'));
        }

        if (version_compare(PHP_VERSION, $php, '<')) {
            throw new RuntimeException(__('This update requires PHP :version or newer.', ['version' => $php]));
        }

        $extensions = $requirements['extensions'] ?? [];
        if (! is_array($extensions)) {
            throw new RuntimeException(__('The update extension requirements are invalid.'));
        }

        foreach ($extensions as $extension) {
            if (! is_string($extension) || preg_match('/\A[a-z0-9_-]+\z/i', $extension) !== 1) {
                throw new RuntimeException(__('The update package contains an invalid extension requirement.'));
            }

            if (! extension_loaded($extension)) {
                throw new RuntimeException(__('This update requires the PHP :extension extension.', ['extension' => $extension]));
            }
        }
    }

    /**
     * @param  list<array{source: string, target: string, sha256: string, size: int}>  $files
     */
    private function extractPayload(ZipArchive $zip, array $files, string $stagingPath): void
    {
        if (! is_dir($stagingPath) && ! mkdir($stagingPath, 0770, true) && ! is_dir($stagingPath)) {
            throw new RuntimeException(__('Unable to create the update staging directory.'));
        }

        foreach ($files as $file) {
            $destination = $stagingPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['source']);
            $directory = dirname($destination);
            if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
                throw new RuntimeException(__('Unable to create an update staging subdirectory.'));
            }

            $sourceStream = $zip->getStream($file['source']);
            if (! is_resource($sourceStream)) {
                throw new RuntimeException(__('Unable to read update payload: :source', ['source' => $file['source']]));
            }

            $destinationStream = fopen($destination, 'wb');
            if (! is_resource($destinationStream)) {
                fclose($sourceStream);

                throw new RuntimeException(__('Unable to stage update payload: :source', ['source' => $file['source']]));
            }

            $copied = stream_copy_to_stream($sourceStream, $destinationStream);
            fclose($sourceStream);
            fclose($destinationStream);

            $stagedHash = hash_file('sha256', $destination);
            if ($copied !== $file['size'] || ! is_string($stagedHash) || ! hash_equals($file['sha256'], $stagedHash)) {
                throw new RuntimeException(__('The staged update payload failed integrity verification: :source', ['source' => $file['source']]));
            }
        }
    }

    /**
     * Decides whether this site needs the package's vendor bundle. Sites whose installed
     * composer.lock already matches keep their vendor directory untouched.
     *
     * @param  array{files: list<array{source: string, target: string, sha256: string, size: int}>, dependencies: array{archive: string, sha256: string, size: int, files: int, bytes: int, composer_lock_sha256: string, php_version_id: int, extensions: list<string>}|null}  $validated
     * @return array{bundle_path: string, files: int, bytes: int, composer_lock_sha256: string}|null
     */
    private function dependencyPlan(ZipArchive $zip, array $validated, string $stagingPath): ?array
    {
        $currentLock = $this->layout->coreRoot().DIRECTORY_SEPARATOR.'composer.lock';
        $targetLock = null;
        foreach ($validated['files'] as $file) {
            if ($file['target'] === 'core/composer.lock') {
                $targetLock = $stagingPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['source']);
            }
        }

        if ($targetLock === null || ! is_file($currentLock)) {
            return null;
        }

        $currentHash = hash_file('sha256', $currentLock);
        $targetHash = hash_file('sha256', $targetLock);
        if (! is_string($currentHash) || ! is_string($targetHash)) {
            throw new RuntimeException(__('Unable to compare Composer dependency locks.'));
        }

        if (hash_equals($currentHash, $targetHash)) {
            return null;
        }

        $bundle = $validated['dependencies'];
        if ($bundle === null) {
            throw new RuntimeException(__('This update changes Composer dependencies, but the package does not include them. Use the cumulative update package from the official release.'));
        }

        if ($bundle['php_version_id'] > PHP_VERSION_ID) {
            throw new RuntimeException(__('The new Composer dependencies require PHP :version or newer; this server runs PHP :current.', [
                'version' => intdiv($bundle['php_version_id'], 10000).'.'.intdiv($bundle['php_version_id'] % 10000, 100).'.'.($bundle['php_version_id'] % 100),
                'current' => PHP_VERSION,
            ]));
        }

        foreach ($bundle['extensions'] as $extension) {
            if (! extension_loaded($extension)) {
                throw new RuntimeException(__('The new Composer dependencies require the PHP :extension extension.', ['extension' => $extension]));
            }
        }

        $destination = $stagingPath.DIRECTORY_SEPARATOR.'dependencies'.DIRECTORY_SEPARATOR.'vendor.zip';
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0770, true) && ! is_dir(dirname($destination))) {
            throw new RuntimeException(__('Unable to create an update staging subdirectory.'));
        }

        $source = $zip->getStream($bundle['archive']);
        $target = fopen($destination, 'wb');
        if (! is_resource($source) || ! is_resource($target)) {
            throw new RuntimeException(__('Unable to stage update payload: :source', ['source' => $bundle['archive']]));
        }
        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        $stagedHash = hash_file('sha256', $destination);
        if ($copied !== $bundle['size'] || ! is_string($stagedHash) || ! hash_equals($bundle['sha256'], $stagedHash)) {
            throw new RuntimeException(__('The staged update payload failed integrity verification: :source', ['source' => $bundle['archive']]));
        }

        return [
            'bundle_path' => $destination,
            'files' => $bundle['files'],
            'bytes' => $bundle['bytes'],
            'composer_lock_sha256' => $bundle['composer_lock_sha256'],
        ];
    }

    private function hashArchiveEntry(ZipArchive $zip, string $path): string
    {
        $stream = $zip->getStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException(__('Unable to read update payload: :path', ['path' => $path]));
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key, int $maximumLength): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || trim($value) === '' || strlen($value) > $maximumLength) {
            throw new RuntimeException(__('The update manifest field :field is invalid.', ['field' => $key]));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private function optionalString(array $values, string $key, int $maximumLength): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '' || strlen($value) > $maximumLength) {
            throw new RuntimeException(__('The update manifest field :field is invalid.', ['field' => $key]));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private function manifestBoolean(array $values, string $key, bool $default): bool
    {
        $value = $values[$key] ?? $default;
        if (! is_bool($value)) {
            throw new RuntimeException(__('The update manifest field :field must be a boolean.', ['field' => $key]));
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredVersion(array $values, string $key): string
    {
        $version = $this->requiredString($values, $key, 64);
        if (preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $version) !== 1) {
            throw new RuntimeException(__('The update manifest field :field is not a valid version.', ['field' => $key]));
        }

        return $version;
    }

    private function isSymbolicLink(ZipArchive $zip, int $index): bool
    {
        $operationsSystem = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $operationsSystem, $attributes)) {
            return false;
        }

        return (($attributes >> 16) & 0xF000) === 0xA000;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
