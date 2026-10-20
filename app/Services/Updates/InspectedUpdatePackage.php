<?php

namespace App\Services\Updates;

final readonly class InspectedUpdatePackage
{
    /**
     * $dependencies is set only when the package must replace vendor/ on this site.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<array{source: string, target: string, sha256: string, size: int}>  $files
     * @param  list<string>  $delete
     * @param  list<string>  $warnings
     * @param  array{bundle_path: string, files: int, bytes: int, composer_lock_sha256: string}|null  $dependencies
     */
    public function __construct(
        public string $packageId,
        public string $name,
        public string $currentVersion,
        public string $targetVersion,
        public string $minimumVersion,
        public string $maximumVersion,
        public string $installationType,
        public array $manifest,
        public array $files,
        public array $delete,
        public array $warnings,
        public bool $migrate,
        public string $archivePath,
        public string $archiveSha256,
        public string $stagingPath,
        public ?array $dependencies = null,
    ) {}

    public function replacesDependencies(): bool
    {
        return $this->dependencies !== null;
    }
}
