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
    /**
     * @param  array{host:string,port:int,database:string,username:string,password:string,charset:string}  $connection
     * @param  list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}>  $requirements
     * @return array{
     *     connected:bool,
     *     compatible:bool|null,
     *     server_version:string|null,
     *     error:string|null,
     *     checks:list<array{table:string,required:bool,table_exists:bool,missing_columns:list<string>}>
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

            $serverVersion = $database->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
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

                    $anyColumns = $requirement['any_columns'] ?? [];
                    if ($anyColumns !== [] && ! $this->containsAnyColumn($tableColumns, $anyColumns)) {
                        $missingColumns[] = implode(' / ', $anyColumns);
                    }
                }

                if ($requirement['required'] && (! $tableExists || $missingColumns !== [])) {
                    $compatible = false;
                }

                $checks[] = [
                    'table' => $requirement['table'],
                    'required' => $requirement['required'],
                    'table_exists' => $tableExists,
                    'missing_columns' => $missingColumns,
                ];
            }

            return [
                'connected' => true,
                'compatible' => $driverReady ? $compatible : null,
                'server_version' => is_scalar($serverVersion) ? (string) $serverVersion : null,
                'error' => null,
                'checks' => $checks,
            ];
        } catch (Throwable $exception) {
            Log::warning('External game database connection test failed.', [
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);

            return [
                'connected' => false,
                'compatible' => false,
                'server_version' => null,
                'error' => 'connection_failed',
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
     */
    private function containsAnyColumn(array $tableColumns, array $alternatives): bool
    {
        foreach ($alternatives as $column) {
            if (in_array(strtolower($column), $tableColumns, true)) {
                return true;
            }
        }

        return false;
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
