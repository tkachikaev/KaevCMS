<?php

namespace App\Services\Updates;

use Illuminate\Database\DatabaseManager;
use Throwable;
use ZipArchive;

final class UpdatePreflight
{
    public function __construct(
        private readonly UpdateInstallationLayout $layout,
        private readonly DatabaseManager $database,
        private readonly UpdatePathPolicy $pathPolicy,
    ) {}

    /**
     * @return list<array{label: string, passed: bool, detail: string}>
     */
    public function inspect(InspectedUpdatePackage $package): array
    {
        $checks = [
            $this->check(
                __('ZIP support'),
                class_exists(ZipArchive::class),
                class_exists(ZipArchive::class) ? __('Available') : __('PHP zip extension is missing.'),
            ),
            $this->check(
                __('Storage directory'),
                is_dir(storage_path()) && is_writable(storage_path()),
                storage_path(),
            ),
            $this->check(
                __('Application root'),
                is_dir($this->layout->coreRoot()) && is_writable($this->layout->coreRoot()),
                $this->layout->coreRoot(),
            ),
            $this->check(
                __('Public directory'),
                is_dir($this->layout->publicRoot()) && is_writable($this->layout->publicRoot()),
                $this->layout->publicRoot(),
            ),
        ];

        $driver = $this->database->connection()->getDriverName();
        $checks[] = $this->check(
            __('Database backup'),
            in_array($driver, ['mysql', 'sqlite'], true),
            in_array($driver, ['mysql', 'sqlite'], true)
                ? __('Automatic backup is available for :driver.', ['driver' => $driver])
                : __('Automatic backup is unavailable for :driver.', ['driver' => $driver]),
        );

        $targets = [];
        foreach ($package->files as $file) {
            $targets[] = $file['target'];
        }
        foreach ($package->delete as $target) {
            $targets[] = $target;
        }

        $unwritableTargets = [];
        foreach (array_values(array_unique($targets)) as $target) {
            $absolute = $this->layout->resolveTarget($target);
            $probe = file_exists($absolute) ? $absolute : $this->existingParent(dirname($absolute));
            if ($probe === null || ! is_writable($probe)) {
                $unwritableTargets[] = $target;
            }
        }

        $checks[] = $this->check(
            __('Update targets'),
            $unwritableTargets === [],
            $unwritableTargets === []
                ? __('All update targets are writable.')
                : __('Unwritable paths: :paths', ['paths' => implode(', ', array_slice($unwritableTargets, 0, 5))]),
        );

        $payloadBytes = 0;
        foreach ($package->files as $file) {
            $payloadBytes += $file['size'];
        }

        $affectedBytes = 0;
        foreach ($this->minimalTargets($targets) as $target) {
            $affectedBytes += $this->pathBytes($this->layout->resolveTarget($target));
        }

        $databaseBytes = $this->estimatedDatabaseBytes($driver);
        $requiredBytes = max(
            104857600,
            ($payloadBytes * 2) + $affectedBytes + (($databaseBytes ?? 0) * 3),
        );
        $freeBytes = @disk_free_space(storage_path());
        $checks[] = $this->check(
            __('Free disk space'),
            $freeBytes === false || $freeBytes >= $requiredBytes,
            $freeBytes === false
                ? __('Could not determine')
                : __(':free available; approximately :required required.', [
                    'free' => $this->formatBytes((int) $freeBytes),
                    'required' => $this->formatBytes($requiredBytes),
                ]),
        );

        if ($databaseBytes === null) {
            $checks[] = $this->check(
                __('Database size estimate'),
                true,
                __('The database size could not be estimated. Verify free disk space and keep an external backup before updating.'),
            );
        }

        return $checks;
    }

    /** @param list<array{label: string, passed: bool, detail: string}> $checks */
    public function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! $check['passed']) {
                return false;
            }
        }

        return true;
    }

    /** @return array{label: string, passed: bool, detail: string} */
    private function check(string $label, bool $passed, string $detail): array
    {
        return compact('label', 'passed', 'detail');
    }

    /**
     * @param  list<string>  $targets
     * @return list<string>
     */
    private function minimalTargets(array $targets): array
    {
        $targets = array_values(array_unique($targets));
        usort($targets, static fn (string $left, string $right): int => strlen($left) <=> strlen($right));

        $result = [];
        foreach ($targets as $target) {
            $targetKey = strtolower($target);
            $covered = false;
            foreach ($result as $existing) {
                if (str_starts_with($targetKey.'/', strtolower($existing).'/')) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                $result[] = $target;
            }
        }

        return $result;
    }

    private function pathBytes(string $path): int
    {
        if (is_link($path)) {
            return 0;
        }

        if (is_file($path)) {
            $size = filesize($path);

            return is_int($size) ? max(0, $size) : 0;
        }

        if (! is_dir($path)) {
            return 0;
        }

        $bytes = 0;
        $items = scandir($path);
        if (! is_array($items)) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $bytes += $this->pathBytes($path.DIRECTORY_SEPARATOR.$item);
        }

        return $bytes;
    }

    private function estimatedDatabaseBytes(string $driver): ?int
    {
        try {
            if ($driver === 'sqlite') {
                $configuredPath = (string) $this->database->connection()->getConfig('database');
                if ($configuredPath === '' || $configuredPath === ':memory:') {
                    return null;
                }

                $path = $this->pathPolicy->isAbsoluteFilesystemPath($configuredPath)
                    ? $configuredPath
                    : base_path($configuredPath);
                $size = is_file($path) ? filesize($path) : false;

                return is_int($size) ? max(0, $size) : null;
            }

            if ($driver === 'mysql') {
                $connection = $this->database->connection();
                $databaseName = $connection->getDatabaseName();
                if ($databaseName === '') {
                    return null;
                }

                $row = $connection->selectOne(
                    'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS aggregate FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
                    [$databaseName],
                );
                $values = is_object($row) ? (array) $row : [];
                $value = $values['aggregate'] ?? null;

                return is_numeric($value) ? max(0, (int) $value) : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function existingParent(string $path): ?string
    {
        $previous = null;
        while ($path !== $previous) {
            if (file_exists($path)) {
                return $path;
            }

            $previous = $path;
            $path = dirname($path);
        }

        return null;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $unit === 0 ? 0 : 1, '.', ' ').' '.$units[$unit];
    }
}
