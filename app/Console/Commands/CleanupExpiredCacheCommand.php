<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CleanupExpiredCacheCommand extends Command
{
    protected $signature = 'kaevcms:cache-clean
        {--batch=1000 : Maximum expired rows deleted per batch}
        {--dry-run : Show current and expired row counts without deleting data}';

    protected $description = 'Remove expired database cache and rate-limiter rows';

    public function handle(): int
    {
        $batchSize = min(10000, max(1, (int) $this->option('batch')));
        $expiration = now()->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($this->databaseTargets() as $target) {
            $connectionName = $target['connection'];
            $table = $target['table'];
            if (! Schema::connection($connectionName)->hasTable($table)) {
                $rows[] = [$connectionName, $table, 0, 0, 0, 'missing'];

                continue;
            }

            $connection = DB::connection($connectionName);
            $total = $connection->table($table)->count();
            $expired = $connection->table($table)->where('expiration', '<=', $expiration)->count();
            $deleted = $dryRun
                ? 0
                : $this->deleteExpiredRows($connection, $table, $expiration, $batchSize);
            $rows[] = [
                $connectionName,
                $table,
                $total,
                $expired,
                $deleted,
                $dryRun ? 'dry-run' : 'cleaned',
            ];
        }

        $this->table(['Connection', 'Table', 'Total', 'Expired', 'Deleted', 'Status'], $rows);

        return self::SUCCESS;
    }

    /** @return list<array{connection:string,table:string}> */
    private function databaseTargets(): array
    {
        /** @var array<string, mixed> $store */
        $store = (array) config('cache.stores.database', []);
        $defaultConnection = (string) config('database.default');
        $cacheConnection = $this->connectionName($store['connection'] ?? null, $defaultConnection);
        $lockConnection = $this->connectionName($store['lock_connection'] ?? null, $cacheConnection);
        $targets = [
            [
                'connection' => $cacheConnection,
                'table' => $this->tableName($store['table'] ?? null, 'cache'),
            ],
            [
                'connection' => $lockConnection,
                'table' => $this->tableName($store['lock_table'] ?? null, 'cache_locks'),
            ],
        ];
        $unique = [];

        foreach ($targets as $target) {
            $unique[$target['connection']."\0".$target['table']] = $target;
        }

        return array_values($unique);
    }

    private function deleteExpiredRows(
        Connection $connection,
        string $table,
        int $expiration,
        int $batchSize,
    ): int {
        $deleted = 0;

        do {
            /** @var Collection<int, string> $keys */
            $keys = $connection->table($table)
                ->where('expiration', '<=', $expiration)
                ->orderBy('expiration')
                ->limit($batchSize)
                ->pluck('key');

            if ($keys->isEmpty()) {
                break;
            }

            $deleted += $connection->table($table)
                ->whereIn('key', $keys->all())
                ->where('expiration', '<=', $expiration)
                ->delete();
        } while ($keys->count() === $batchSize);

        return $deleted;
    }

    private function connectionName(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    private function tableName(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }
}
