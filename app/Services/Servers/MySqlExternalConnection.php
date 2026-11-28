<?php

namespace App\Services\Servers;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/** Shared connection configuration and lifecycle for external MySQL operations. */
final class MySqlExternalConnection
{
    public function __construct(private readonly MySqlSessionQueryTimeout $queryTimeout) {}

    /** @param array{host:string,port:int,database:string,username:string,password:string,charset:string,collation?:string} $values
     * @return array<string,mixed>
     */
    public static function configuration(array $values): array
    {
        $charset = $values['charset'] !== '' ? $values['charset'] : 'utf8mb4';

        return [
            'driver' => 'mysql',
            'host' => $values['host'],
            'port' => $values['port'],
            'database' => $values['database'],
            'username' => $values['username'],
            'password' => $values['password'],
            'charset' => $charset,
            'collation' => $values['collation'] ?? match ($charset) {
                'utf8' => 'utf8_unicode_ci',
                'latin1' => 'latin1_swedish_ci',
                'cp1251' => 'cp1251_general_ci',
                default => 'utf8mb4_unicode_ci',
            },
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_TIMEOUT => max(1, min(30, (int) config('cms.external_database.connect_timeout_seconds', 3))),
            ],
        ];
    }

    /** @template TResult
     * @param array{host:string,port:int,database:string,username:string,password:string,charset:string,collation?:string} $values
     * @param callable(Connection): TResult $callback
     * @return TResult
     */
    public function run(string $prefix, array $values, callable $callback): mixed
    {
        $name = $prefix.Str::lower(Str::random(12));
        try {
            $database = DB::connectUsing($name, self::configuration($values), true);
            if (! $database instanceof Connection) {
                throw new RuntimeException('Unsupported external database connection type.');
            }
            $this->queryTimeout->apply($database);

            return $callback($database);
        } finally {
            try {
                DB::purge($name);
            } catch (Throwable) {
                // Cleanup must not replace the operation result.
            }
        }
    }
}
