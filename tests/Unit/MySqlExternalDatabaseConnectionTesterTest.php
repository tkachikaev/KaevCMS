<?php

namespace Tests\Unit;

use App\Services\Servers\MySqlExternalDatabaseConnectionTester;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
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
