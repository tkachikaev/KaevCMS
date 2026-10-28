<?php

namespace App\Services\Servers;

use App\Contracts\ExternalDatabaseConnectionTester;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class MySqlExternalDatabaseConnectionTester implements ExternalDatabaseConnectionTester
{
    public function __construct(private readonly MySqlSessionQueryTimeout $queryTimeout) {}

    /**
     * @param  array{host:string,port:int,database:string,username:string,password:string,charset:string,collation?:string}  $connection
     * @param  list<array{table:string,columns:list<string>,any_columns?:list<string>,optional_columns?:list<string>,required:bool}>  $requirements
     * @return array{
     *     connected:bool,
     *     compatible:bool|null,
     *     server_version:string|null,
     *     error:string|null,
     *     error_class:string|null,
     *     error_stage?:string,
     *     sqlstate?:string|null,
     *     latency_ms:int|null,
     *     checks:list<array{table:string,required:bool,table_exists:bool,missing_columns:list<string>,matched_any_columns:list<string>,matched_optional_columns:list<string>}>
     * }
     */
    public function test(array $connection, array $requirements, bool $driverReady): array
    {
        return $this->probe($connection, $requirements, $driverReady);
    }

    /**
     * Verify additional read-only invariants before allowing a JSON profile to be applied.
     * The regular connection test for legacy PHP drivers is unchanged.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $connection
     * @return array<string,mixed>
     */
    public function testProfile(array $connection, array $profile): array
    {
        return $this->probe($connection, $profile['config']['requirements'], true, $profile);
    }

    /**
     * @param  array<string,mixed>  $connection
     * @param  list<array<string,mixed>>  $requirements
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    private function probe(array $connection, array $requirements, bool $driverReady, ?array $profile = null): array
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
            'collation' => $connection['collation'] ?? $this->collationFor($connection['charset']),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_TIMEOUT => max(1, min(30, (int) config('cms.external_database.connect_timeout_seconds', 3))),
            ],
        ];

        $stage = 'connection';
        $connected = false;
        $serverVersion = null;
        $latencyMs = null;
        $checks = [];
        $failure = null;

        try {
            $database = DB::connectUsing($connectionName, $configuration, true);
            if (! $database instanceof Connection) {
                throw new RuntimeException('Unsupported external database connection type.');
            }

            $pdo = $this->queryTimeout->withNetworkReadTimeout(static fn (): PDO => $database->getPdo());
            $connected = true;
            $stage = 'health_check';
            $queryStartedAt = hrtime(true);
            $database->selectOne('select 1 as kaevcms_health');
            $latencyMs = $this->millisecondsSince($queryStartedAt);
            $stage = 'server_version';
            $versionValue = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $version = is_string($versionValue) ? $versionValue : '';
            $maria = stripos($version, 'MariaDB') !== false;
            // MariaDB may prefix its actual version with MySQL's compatibility version.
            $version = $maria ? preg_replace('/^5\.5\.5-/', '', $version) : $version;
            if (preg_match('/^(\d+\.\d+\.\d+)/', (string) $version, $matches) !== 1) {
                $failure = 'version_check_failed';
                throw new RuntimeException('Unrecognized external database version.');
            }
            $serverVersion = $matches[1].($maria ? '-MariaDB' : '');
            if (version_compare($matches[1], $maria ? '10.3.0' : '5.7.0', '<')) {
                $failure = 'unsupported_version';
                throw new RuntimeException('Unsupported external database version.');
            }
            $stage = 'session_setup';
            $this->queryTimeout->apply($database, $serverVersion);
            $stage = 'schema_tables';
            $schema = $database->getSchemaBuilder();
            $checks = [];
            $compatible = $driverReady;

            foreach ($requirements as $requirement) {
                $stage = 'schema_tables';
                $tableExists = $schema->hasTable($requirement['table']);
                $stage = 'schema_columns';
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
                $optionalColumns = $requirement['optional_columns'] ?? [];
                $matchedOptionalColumns = $this->matchedColumns($tableColumns, $optionalColumns);
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
                    'matched_optional_columns' => $matchedOptionalColumns,
                ];
            }

            $profileIssues = [];
            if ($compatible && $profile !== null) {
                $stage = 'profile_schema';
                $profileIssues = (new UniversalProfileSchemaProbe)->problems($database, $profile);
                if ($profileIssues !== []) {
                    $compatible = false;
                }
            }

            return [
                'profile_issues' => $profileIssues,
                'connected' => true,
                'compatible' => $driverReady ? $compatible : null,
                'server_version' => $serverVersion,
                'error' => null,
                'error_class' => null,
                'latency_ms' => $latencyMs,
                'checks' => $checks,
            ];
        } catch (Throwable $exception) {
            $info = $exception instanceof PDOException ? $exception->errorInfo : null;
            $sqlstate = is_array($info) ? ($info[0] ?? null) : (string) $exception->getCode();
            $sqlstate = is_string($sqlstate) && preg_match('/\A[A-Z0-9]{5}\z/D', $sqlstate) ? $sqlstate : null;
            $nativeCode = is_array($info) && is_numeric($info[1] ?? null) ? (int) $info[1] : null;
            $error = $failure ?? match (true) {
                in_array($nativeCode, [1045, 1698], true) => 'authentication_failed',
                $nativeCode === 1049 => 'database_not_found',
                in_array($nativeCode, [2002, 2003, 2005, 2006, 2013], true) => 'server_unreachable',
                in_array($stage, ['schema_tables', 'schema_columns', 'profile_schema'], true) => 'schema_check_failed',
                $stage === 'health_check' => 'health_check_failed',
                $stage === 'server_version' => 'version_check_failed',
                default => 'connection_failed',
            };
            Log::warning('External database diagnostic failed.', [
                'stage' => $stage,
                'exception_class' => $exception::class,
                'sqlstate' => $sqlstate,
                'driver_error_code' => $nativeCode,
                'server_version' => $serverVersion,
                'connected' => $connected,
                // A fingerprint allows comparison without logging SQL identifiers or bindings.
                'sql_sha256' => $exception instanceof QueryException ? hash('sha256', $exception->getSql()) : null,
            ]);

            return [
                'profile_issues' => [],
                'connected' => $connected,
                'compatible' => false,
                'server_version' => $serverVersion,
                'error' => $error,
                'error_class' => $exception::class,
                'error_stage' => $stage,
                'sqlstate' => $sqlstate,
                'latency_ms' => $latencyMs,
                'checks' => $checks,
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
