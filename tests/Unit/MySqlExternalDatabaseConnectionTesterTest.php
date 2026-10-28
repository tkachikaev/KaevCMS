<?php

namespace Tests\Unit;

use App\Services\Servers\MySqlExternalDatabaseConnectionTester;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PDO;
use RuntimeException;
use Tests\TestCase;

class MySqlExternalDatabaseConnectionTesterTest extends TestCase
{
    public function test_failed_connection_is_reported_without_cleanup_exception(): void
    {
        $database = Mockery::mock(Connection::class);
        $database->shouldReceive('getPdo')
            ->once()
            ->andThrow(new RuntimeException('Connection refused.'));

        DB::shouldReceive('connectUsing')
            ->once()
            ->with(
                Mockery::on(fn (string $name): bool => str_starts_with($name, 'kaevcms_external_')),
                Mockery::on(fn (array $configuration): bool => $configuration['driver'] === 'mysql'
                    && $configuration['host'] === '127.0.0.1'
                    && $configuration['password'] === 'SecretDatabasePassword'),
                true,
            )
            ->andReturn($database);
        DB::shouldReceive('purge')
            ->once()
            ->with(Mockery::on(fn (string $name): bool => str_starts_with($name, 'kaevcms_external_')));

        $report = app(MySqlExternalDatabaseConnectionTester::class)->test([
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'l2jmobiusinterlude',
            'username' => 'kaevcms_db',
            'password' => 'SecretDatabasePassword',
            'charset' => 'utf8mb4',
        ], [], true);

        $this->assertFalse($report['connected']);
        $this->assertFalse($report['compatible']);
        $this->assertSame('connection_failed', $report['error']);
        $this->assertSame(RuntimeException::class, $report['error_class']);
        $this->assertNull($report['latency_ms']);
        $this->assertSame([], $report['checks']);
    }

    public function test_any_column_requirement_accepts_modern_reputation_schema(): void
    {
        $database = Mockery::mock(Connection::class);
        $schema = Mockery::mock(Builder::class);
        $pdo = Mockery::mock(PDO::class);

        $pdo->shouldReceive('getAttribute')->once()->with(PDO::ATTR_SERVER_VERSION)->andReturn('8.4.0');
        $database->shouldReceive('getPdo')->once()->andReturn($pdo);
        $database->shouldReceive('unprepared')->once()->with('SET SESSION innodb_lock_wait_timeout = 10')->andReturnTrue();
        $database->shouldReceive('unprepared')->once()->with('SET SESSION max_execution_time = 3000')->andReturnTrue();
        $database->shouldReceive('selectOne')->once()->with('select 1 as kaevcms_health')->andReturn((object) ['kaevcms_health' => 1]);
        $database->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $schema->shouldReceive('hasTable')->once()->with('characters')->andReturnTrue();
        $schema->shouldReceive('getColumnListing')->once()->with('characters')->andReturn(['charId', 'reputation', 'x', 'y']);

        DB::shouldReceive('connectUsing')->once()->andReturn($database);
        DB::shouldReceive('purge')->once();

        $report = app(MySqlExternalDatabaseConnectionTester::class)->test($this->connectionValues(), [[
            'table' => 'characters',
            'columns' => ['charId'],
            'any_columns' => ['karma', 'reputation'],
            'optional_columns' => ['x', 'y', 'z'],
            'required' => true,
        ]], true);

        $this->assertTrue($report['connected']);
        $this->assertTrue($report['compatible']);
        $this->assertSame([], $report['checks'][0]['missing_columns']);
        $this->assertSame(['reputation'], $report['checks'][0]['matched_any_columns']);
        $this->assertSame(['x', 'y'], $report['checks'][0]['matched_optional_columns']);
        $this->assertIsInt($report['latency_ms']);
    }

    public function test_any_column_requirement_rejects_schema_without_karma_or_reputation(): void
    {
        $database = Mockery::mock(Connection::class);
        $schema = Mockery::mock(Builder::class);
        $pdo = Mockery::mock(PDO::class);

        $pdo->shouldReceive('getAttribute')->once()->with(PDO::ATTR_SERVER_VERSION)->andReturn('8.4.0');
        $database->shouldReceive('getPdo')->once()->andReturn($pdo);
        $database->shouldReceive('unprepared')->once()->with('SET SESSION innodb_lock_wait_timeout = 10')->andReturnTrue();
        $database->shouldReceive('unprepared')->once()->with('SET SESSION max_execution_time = 3000')->andReturnTrue();
        $database->shouldReceive('selectOne')->once()->with('select 1 as kaevcms_health')->andReturn((object) ['kaevcms_health' => 1]);
        $database->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $schema->shouldReceive('hasTable')->once()->with('characters')->andReturnTrue();
        $schema->shouldReceive('getColumnListing')->once()->with('characters')->andReturn(['charId']);

        DB::shouldReceive('connectUsing')->once()->andReturn($database);
        DB::shouldReceive('purge')->once();

        $report = app(MySqlExternalDatabaseConnectionTester::class)->test($this->connectionValues(), [[
            'table' => 'characters',
            'columns' => ['charId'],
            'any_columns' => ['karma', 'reputation'],
            'required' => true,
        ]], true);

        $this->assertTrue($report['connected']);
        $this->assertFalse($report['compatible']);
        $this->assertSame(['karma / reputation'], $report['checks'][0]['missing_columns']);
        $this->assertSame([], $report['checks'][0]['matched_any_columns']);
        $this->assertSame([], $report['checks'][0]['matched_optional_columns']);
    }

    public function test_health_and_version_failures_do_not_claim_the_connection_failed(): void
    {
        foreach (['health_check', 'server_version', 'unrecognized_version'] as $stage) {
            $database = Mockery::mock(Connection::class);
            $pdo = Mockery::mock(PDO::class);
            $database->shouldReceive('getPdo')->once()->andReturn($pdo);
            $health = $database->shouldReceive('selectOne')->once()->with('select 1 as kaevcms_health');
            if ($stage === 'health_check') {
                $health->andThrow(new RuntimeException('Sensitive failure'));
                $pdo->shouldNotReceive('getAttribute');
            } else {
                $health->andReturn((object) ['kaevcms_health' => 1]);
                $version = $pdo->shouldReceive('getAttribute')->once()->with(PDO::ATTR_SERVER_VERSION);
                if ($stage === 'server_version') {
                    $version->andThrow(new RuntimeException('Sensitive failure'));
                } else {
                    $version->andReturn('unrecognized SensitiveVersion');
                }
            }
            $database->shouldNotReceive('getSchemaBuilder');
            DB::shouldReceive('connectUsing')->once()->andReturn($database);
            DB::shouldReceive('purge')->once();
            $report = app(MySqlExternalDatabaseConnectionTester::class)->test($this->connectionValues(), [], true);
            $this->assertTrue($report['connected']);
            $this->assertFalse($report['compatible']);
            $this->assertSame($stage === 'health_check' ? 'health_check_failed' : 'version_check_failed', $report['error']);
            $this->assertNull($report['server_version']);
            $this->assertStringNotContainsString('Sensitive', json_encode($report, JSON_THROW_ON_ERROR));
        }
    }

    public function test_versions_are_checked_before_schema_queries(): void
    {
        foreach (['5.5.43' => false, '5.6.51' => false, '5.7.44' => true, '8.4.0' => true,
            '5.5.5-10.11.14-MariaDB' => true, '10.2.44-MariaDB' => false] as $version => $supported) {
            $database = Mockery::mock(Connection::class);
            $pdo = Mockery::mock(PDO::class);
            $database->shouldReceive('getPdo')->once()->andReturn($pdo);
            $database->shouldReceive('selectOne')->once()->with('select 1 as kaevcms_health')->andReturn((object) ['kaevcms_health' => 1]);
            $pdo->shouldReceive('getAttribute')->once()->with(PDO::ATTR_SERVER_VERSION)->andReturn($version);
            if ($supported) {
                $database->shouldReceive('unprepared')->andReturnTrue();
                $database->shouldReceive('getSchemaBuilder')->once()->andReturn(Mockery::mock(Builder::class));
            } else {
                $database->shouldNotReceive('getSchemaBuilder');
            }
            DB::shouldReceive('connectUsing')->once()->andReturn($database);
            DB::shouldReceive('purge')->once();
            $result = app(MySqlExternalDatabaseConnectionTester::class)->test($this->connectionValues(), [], true);
            $this->assertTrue($result['connected'], $version);
            $this->assertSame($supported, $result['compatible'], $version);
            $this->assertSame($supported ? null : 'unsupported_version', $result['error'], $version);
        }
    }

    public function test_authentication_and_network_errors_are_distinct_and_do_not_leak_secrets(): void
    {
        foreach ([1045 => 'authentication_failed', 1049 => 'database_not_found', 2002 => 'server_unreachable'] as $code => $expected) {
            $exception = new \PDOException('secret-host SecretDatabasePassword');
            $exception->errorInfo = ['HY000', $code, 'secret-host SecretDatabasePassword'];
            $database = Mockery::mock(Connection::class);
            $database->shouldReceive('getPdo')->once()->andThrow($exception);
            DB::shouldReceive('connectUsing')->once()->andReturn($database);
            DB::shouldReceive('purge')->once();
            Log::spy();
            $result = app(MySqlExternalDatabaseConnectionTester::class)->test($this->connectionValues(), [], true);
            $this->assertFalse($result['connected']);
            $this->assertSame($expected, $result['error']);
            $this->assertSame('connection', $result['error_stage']);
            $this->assertStringNotContainsString('SecretDatabasePassword', json_encode($result, JSON_THROW_ON_ERROR));
            Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use ($code) {
                $this->assertStringNotContainsString('secret-host', json_encode($context, JSON_THROW_ON_ERROR));
                $this->assertStringNotContainsString('SecretDatabasePassword', json_encode($context, JSON_THROW_ON_ERROR));

                return $context['sqlstate'] === 'HY000' && $context['driver_error_code'] === $code;
            })->once();
        }
    }

    public function test_schema_sql_failure_retains_connection_version_and_safe_diagnostics(): void
    {
        $pdoError = new \PDOException('Unknown column: secret-table SecretDatabasePassword');
        $pdoError->errorInfo = ['42S22', 1054, 'secret-table SecretDatabasePassword'];
        $query = new QueryException('secret-db', 'select generation_expression from secret_table', [], $pdoError);
        $database = Mockery::mock(Connection::class);
        $pdo = Mockery::mock(PDO::class);
        $database->shouldReceive('getPdo')->once()->andReturn($pdo);
        $database->shouldReceive('selectOne')->once()->with('select 1 as kaevcms_health')->andReturn((object) ['kaevcms_health' => 1]);
        $pdo->shouldReceive('getAttribute')->once()->with(PDO::ATTR_SERVER_VERSION)->andReturn('8.4.0');
        $database->shouldReceive('unprepared')->andReturnTrue();
        $schema = Mockery::mock(Builder::class);
        $database->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $schema->shouldReceive('hasTable')->once()->with('accounts')->andReturnTrue();
        $schema->shouldReceive('getColumnListing')->once()->with('accounts')->andThrow($query);
        DB::shouldReceive('connectUsing')->once()->andReturn($database);
        DB::shouldReceive('purge')->once();
        Log::spy();
        $result = app(MySqlExternalDatabaseConnectionTester::class)->test($this->connectionValues(), [
            ['table' => 'accounts', 'columns' => ['login'], 'required' => true],
        ], true);
        $this->assertTrue($result['connected']);
        $this->assertFalse($result['compatible']);
        $this->assertSame('8.4.0', $result['server_version']);
        $this->assertSame('schema_check_failed', $result['error']);
        $this->assertSame('schema_columns', $result['error_stage']);
        $this->assertSame('42S22', $result['sqlstate']);
        $this->assertIsInt($result['latency_ms']);
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use ($query) {
            $this->assertSame(hash('sha256', $query->getSql()), $context['sql_sha256']);
            $this->assertStringNotContainsString('secret', json_encode($context, JSON_THROW_ON_ERROR));

            return $context['stage'] === 'schema_columns' && $context['sqlstate'] === '42S22';
        })->once();
    }

    /** @return array{host:string,port:int,database:string,username:string,password:string,charset:string} */
    private function connectionValues(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'l2jmobius',
            'username' => 'kaevcms',
            'password' => 'SecretDatabasePassword',
            'charset' => 'utf8mb4',
        ];
    }
}
