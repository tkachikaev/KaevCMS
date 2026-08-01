<?php

namespace App\Services\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

final class StorageOverview
{
    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    /**
     * @return array{
     *     disk: array{
     *         available: bool,
     *         total_bytes: int|null,
     *         used_bytes: int|null,
     *         free_bytes: int|null,
     *         used_percent: float|null,
     *         free_percent: float|null,
     *         total: string|null,
     *         used: string|null,
     *         free: string|null,
     *         state: string
     *     },
     *     database: array{
     *         connected: bool,
     *         driver: string,
     *         driver_label: string,
     *         version: string|null,
     *         statistics_available: bool,
     *         total_bytes: int|null,
     *         data_bytes: int|null,
     *         index_bytes: int|null,
     *         table_count: int|null,
     *         total: string|null,
     *         data: string|null,
     *         indexes: string|null,
     *         error: string|null
     *     }
     * }
     */
    public function collect(): array
    {
        return [
            'disk' => $this->diskInformation(),
            'database' => $this->databaseInformation(),
        ];
    }

    /**
     * @return array{
     *     available: bool,
     *     total_bytes: int|null,
     *     used_bytes: int|null,
     *     free_bytes: int|null,
     *     used_percent: float|null,
     *     free_percent: float|null,
     *     total: string|null,
     *     used: string|null,
     *     free: string|null,
     *     state: string
     * }
     */
    private function diskInformation(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        if (! is_numeric($free) || ! is_numeric($total) || (float) $total <= 0) {
            return [
                'available' => false,
                'total_bytes' => null,
                'used_bytes' => null,
                'free_bytes' => null,
                'used_percent' => null,
                'free_percent' => null,
                'total' => null,
                'used' => null,
                'free' => null,
                'state' => 'neutral',
            ];
        }

        $totalBytes = max(0, (int) $total);
        $freeBytes = max(0, min($totalBytes, (int) $free));
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $usedPercent = round(($usedBytes / $totalBytes) * 100, 1);
        $freePercent = round(($freeBytes / $totalBytes) * 100, 1);
        $minimumBytes = max(268_435_456, (int) config('cms.admin_notifications.minimum_free_bytes', 1_073_741_824));
        $minimumPercent = max(1.0, min(25.0, (float) config('cms.admin_notifications.minimum_free_percent', 5.0)));

        $state = match (true) {
            $freeBytes < 268_435_456 || $freePercent < 1.0 => 'danger',
            $freeBytes < $minimumBytes || $freePercent < $minimumPercent => 'warning',
            default => 'success',
        };

        return [
            'available' => true,
            'total_bytes' => $totalBytes,
            'used_bytes' => $usedBytes,
            'free_bytes' => $freeBytes,
            'used_percent' => $usedPercent,
            'free_percent' => $freePercent,
            'total' => $this->formatBytes($totalBytes),
            'used' => $this->formatBytes($usedBytes),
            'free' => $this->formatBytes($freeBytes),
            'state' => $state,
        ];
    }

    /**
     * @return array{
     *     connected: bool,
     *     driver: string,
     *     driver_label: string,
     *     version: string|null,
     *     statistics_available: bool,
     *     total_bytes: int|null,
     *     data_bytes: int|null,
     *     index_bytes: int|null,
     *     table_count: int|null,
     *     total: string|null,
     *     data: string|null,
     *     indexes: string|null,
     *     error: string|null
     * }
     */
    private function databaseInformation(): array
    {
        $connectionName = (string) config('database.default');
        $driver = (string) config("database.connections.{$connectionName}.driver", 'unknown');
        $version = null;
        $connected = false;
        $error = null;
        $statistics = [
            'total_bytes' => null,
            'data_bytes' => null,
            'index_bytes' => null,
            'table_count' => null,
        ];

        try {
            $connection = $this->database->connection($connectionName);
            $connection->select('select 1');
            $connected = true;
            $version = $this->serverVersion($connection);
            $statistics = match ($driver) {
                'mysql' => $this->mysqlStatistics($connection),
                'sqlite' => $this->sqliteStatistics($connectionName, $connection),
                default => $statistics,
            };
        } catch (Throwable $exception) {
            $error = $exception::class;
        }

        $totalBytes = $statistics['total_bytes'];
        $dataBytes = $statistics['data_bytes'];
        $indexBytes = $statistics['index_bytes'];
        $statisticsAvailable = $statistics['table_count'] !== null
            || $totalBytes !== null
            || $dataBytes !== null
            || $indexBytes !== null;

        return [
            'connected' => $connected,
            'driver' => $driver,
            'driver_label' => $this->databaseDriverLabel($driver, $version),
            'version' => $version,
            'statistics_available' => $statisticsAvailable,
            'total_bytes' => $totalBytes,
            'data_bytes' => $dataBytes,
            'index_bytes' => $indexBytes,
            'table_count' => $statistics['table_count'],
            'total' => $totalBytes !== null ? $this->formatBytes($totalBytes) : null,
            'data' => $dataBytes !== null ? $this->formatBytes($dataBytes) : null,
            'indexes' => $indexBytes !== null ? $this->formatBytes($indexBytes) : null,
            'error' => $error,
        ];
    }

    private function serverVersion(Connection $connection): ?string
    {
        try {
            $value = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

            return is_scalar($value) ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{total_bytes: int|null, data_bytes: int|null, index_bytes: int|null, table_count: int|null} */
    private function mysqlStatistics(Connection $connection): array
    {
        try {
            $row = $connection->selectOne(<<<'SQL'
                SELECT
                    COUNT(*) AS table_count,
                    COALESCE(SUM(DATA_LENGTH), 0) AS data_bytes,
                    COALESCE(SUM(INDEX_LENGTH), 0) AS index_bytes
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_TYPE = 'BASE TABLE'
            SQL);
            $values = is_object($row) ? get_object_vars($row) : [];
            $dataBytes = $this->nullableInteger($values['data_bytes'] ?? null);
            $indexBytes = $this->nullableInteger($values['index_bytes'] ?? null);

            return [
                'total_bytes' => $dataBytes !== null && $indexBytes !== null ? $dataBytes + $indexBytes : null,
                'data_bytes' => $dataBytes,
                'index_bytes' => $indexBytes,
                'table_count' => $this->nullableInteger($values['table_count'] ?? null),
            ];
        } catch (Throwable) {
            return [
                'total_bytes' => null,
                'data_bytes' => null,
                'index_bytes' => null,
                'table_count' => null,
            ];
        }
    }

    /** @return array{total_bytes: int|null, data_bytes: int|null, index_bytes: int|null, table_count: int|null} */
    private function sqliteStatistics(string $connectionName, Connection $connection): array
    {
        $databasePath = (string) config("database.connections.{$connectionName}.database", '');
        $totalBytes = $this->sqliteFileBytes($databasePath);
        $tableCount = null;
        $dataBytes = null;
        $indexBytes = null;

        try {
            $row = $connection->selectOne(<<<'SQL'
                SELECT COUNT(*) AS table_count
                FROM sqlite_master
                WHERE type = 'table'
                  AND name NOT LIKE 'sqlite_%'
            SQL);
            $values = is_object($row) ? get_object_vars($row) : [];
            $tableCount = $this->nullableInteger($values['table_count'] ?? null);
        } catch (Throwable) {
            $tableCount = null;
        }

        try {
            $row = $connection->selectOne(<<<'SQL'
                SELECT
                    COALESCE(SUM(CASE WHEN master.type = 'index' THEN stat.pgsize ELSE 0 END), 0) AS index_bytes,
                    COALESCE(SUM(CASE WHEN master.type = 'index' THEN 0 ELSE stat.pgsize END), 0) AS data_bytes
                FROM dbstat AS stat
                LEFT JOIN sqlite_master AS master ON master.name = stat.name
                WHERE stat.name NOT LIKE 'sqlite_%'
            SQL);
            $values = is_object($row) ? get_object_vars($row) : [];
            $dataBytes = $this->nullableInteger($values['data_bytes'] ?? null);
            $indexBytes = $this->nullableInteger($values['index_bytes'] ?? null);
        } catch (Throwable) {
            // The optional SQLite dbstat virtual table is not available on every host.
        }

        return [
            'total_bytes' => $totalBytes,
            'data_bytes' => $dataBytes,
            'index_bytes' => $indexBytes,
            'table_count' => $tableCount,
        ];
    }

    private function sqliteFileBytes(string $databasePath): ?int
    {
        if ($databasePath === '' || $databasePath === ':memory:' || ! is_file($databasePath)) {
            return null;
        }

        clearstatcache(true, $databasePath);
        $total = max(0, (int) (@filesize($databasePath) ?: 0));

        foreach ([$databasePath.'-wal', $databasePath.'-shm'] as $companionPath) {
            if (! is_file($companionPath)) {
                continue;
            }

            clearstatcache(true, $companionPath);
            $total += max(0, (int) (@filesize($companionPath) ?: 0));
        }

        return $total;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value) && is_numeric($value)
            ? max(0, (int) $value)
            : null;
    }

    private function databaseDriverLabel(string $driver, ?string $version): string
    {
        return match ($driver) {
            'mysql' => is_string($version) && str_contains(strtolower($version), 'mariadb') ? 'MariaDB' : 'MySQL',
            'sqlite' => 'SQLite',
            'pgsql' => 'PostgreSQL',
            'sqlsrv' => 'SQL Server',
            default => strtoupper($driver),
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return __(':bytes B', ['bytes' => $bytes]);
        }

        $units = [__('KB'), __('MB'), __('GB'), __('TB')];
        $value = $bytes / 1024;
        $unit = $units[0];

        foreach (array_slice($units, 1) as $nextUnit) {
            if ($value < 1024) {
                break;
            }

            $value /= 1024;
            $unit = $nextUnit;
        }

        return number_format(
            $value,
            $value >= 10 ? 1 : 2,
            app()->getLocale() === 'ru' ? ',' : '.',
            app()->getLocale() === 'ru' ? ' ' : ',',
        ).' '.$unit;
    }
}
