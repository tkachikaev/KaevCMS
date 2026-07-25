<?php

namespace App\Services\Updates;

final readonly class InspectedUpdatePackage
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{source: string, target: string, sha256: string, size: int}>  $files
     * @param  list<string>  $delete
     * @param  list<string>  $warnings
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
    ) {}
}
