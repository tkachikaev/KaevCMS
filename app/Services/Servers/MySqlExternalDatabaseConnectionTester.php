<?php

namespace App\Services\Servers;

use App\Contracts\ExternalDatabaseConnectionTester;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

final class MySqlExternalDatabaseConnectionTester implements ExternalDatabaseConnectionTester
{
    public function __construct(private readonly MySqlSessionQueryTimeout $queryTimeout) {}

    /**
     * @param  array{host:string,port:int,database:string,username:string,password:string,charset:string}  $connection
     * @param  list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}>  $requirements
     * @return array{
     *     connected:bool,
     *     compatible:bool|null,
     *     server_version:string|null,
     *     error:string|null,
     *     error_class:string|null,
     *     latency_ms:int|null,
     *     checks:list<array{table:string,required:bool,table_exists:bool,missing_columns:list<string>,matched_any_columns:list<string>}>
     * }
     */
    public function test(array $connection, array $requirements, bool $driverReady): array
    {
        $connectionName = 'kaevcms_external_'.Str::lower(Str::random(12));
        $configuration = [
            'driver' => 'mysql',
            'host' => $connection['host'],
            'port' => $connection['port'],
            'database' => $connection['database'],
            'username' => $connection['username'],
            'password' => $connection['password'],
            'charset' => $connection['charset'],
            'collation' => $this->collationFor($connection['charset']),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_TIMEOUT => max(1, min(30, (int) config('cms.external_database.connect_timeout_seconds', 3))),
            ],
        ];

        try {
            $database = DB::connectUsing($connectionName, $configuration, true);
            if (! $database instanceof Connection) {
                throw new RuntimeException('Unsupported external database connection type.');
            }

            $serverVersionValue = $database->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            $serverVersion = is_scalar($serverVersionValue) ? (string) $serverVersionValue : null;
            $this->queryTimeout->apply($database, $serverVersion);
            $queryStartedAt = hrtime(true);
            $database->selectOne('select 1 as kaevcms_health');
            $latencyMs = $this->millisecondsSince($queryStartedAt);
            $schema = $database->getSchemaBuilder();
            $checks = [];
            $compatible = $driverReady;

            foreach ($requirements as $requirement) {
                $tableExists = $schema->hasTable($requirement['table']);
                $tableColumns = $tableExists
                    ? array_map(strtolower(...), $schema->getColumnListing($requirement['table']))
                    : [];
                $missingColumns = [];

                if ($tableExists) {
                    foreach ($requirement['columns'] as $column) {
                        if (! in_array(strtolower($column), $tableColumns, true)) {
                            $missingColumns[] = $column;
                        }
                    }
                }

                $anyColumns = $requirement['any_columns'] ?? [];
                $matchedAnyColumns = $this->matchedColumns($tableColumns, $anyColumns);
                if ($tableExists && $anyColumns !== [] && $matchedAnyColumns === []) {
                    $missingColumns[] = implode(' / ', $anyColumns);
                }

                if ($requirement['required'] && (! $tableExists || $missingColumns !== [])) {
                    $compatible = false;
                }

                $checks[] = [
                    'table' => $requirement['table'],
                    'required' => $requirement['required'],
                    'table_exists' => $tableExists,
                    'missing_columns' => $missingColumns,
                    'matched_any_columns' => $matchedAnyColumns,
                ];
            }

            return [
                'connected' => true,
                'compatible' => $driverReady ? $compatible : null,
                'server_version' => $serverVersion,
                'error' => null,
                'error_class' => null,
                'latency_ms' => $latencyMs,
                'checks' => $checks,
            ];
        } catch (Throwable $exception) {
            Log::warning('External database connection test failed.', [
                'exception_class' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);

            return [
                'connected' => false,
                'compatible' => false,
                'server_version' => null,
                'error' => 'connection_failed',
                'error_class' => $exception::class,
                'latency_ms' => null,
                'checks' => [],
            ];
        } finally {
            try {
                DB::purge($connectionName);
            } catch (Throwable) {
                // The test result must not be replaced by a cleanup failure.
            }
        }
    }

    /**
     * @param  list<string>  $tableColumns
     * @param  list<string>  $alternatives
     * @return list<string>
     */
    private function matchedColumns(array $tableColumns, array $alternatives): array
    {
        $matched = [];

        foreach ($alternatives as $column) {
            if (in_array(strtolower($column), $tableColumns, true)) {
                $matched[] = $column;
            }
        }

        return $matched;
    }

    private function millisecondsSince(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function collationFor(string $charset): string
    {
        return match ($charset) {
            'utf8' => 'utf8_unicode_ci',
            'latin1' => 'latin1_swedish_ci',
            'cp1251' => 'cp1251_general_ci',
            default => 'utf8mb4_unicode_ci',
        };
    }
}
