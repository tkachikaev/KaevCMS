<?php

namespace Tests\Unit;

use App\Services\Servers\MySqlSessionQueryTimeout;
use Illuminate\Database\Connection;
use Mockery;
use PDO;
use RuntimeException;
use Tests\TestCase;

class MySqlSessionQueryTimeoutTest extends TestCase
{
    public function test_mariadb_timeout_is_configured_in_seconds(): void
    {
        $timeout = new MySqlSessionQueryTimeout;

        $this->assertSame(
            'SET SESSION max_statement_time = 3.000',
            $timeout->statementFor('10.4.32-MariaDB', 3000),
        );
    }

    public function test_mysql_timeout_is_configured_in_milliseconds(): void
    {
        $timeout = new MySqlSessionQueryTimeout;

        $this->assertSame(
            'SET SESSION max_execution_time = 3000',
            $timeout->statementFor('8.0.42', 3000),
        );
    }

    public function test_timeout_is_applied_to_the_connection_session(): void
    {
        config()->set('cms.external_database.query_timeout_ms', 3000);

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')
            ->once()
            ->with(PDO::ATTR_SERVER_VERSION)
            ->andReturn('10.4.32-MariaDB');

        $database = Mockery::mock(Connection::class);
        $database->shouldReceive('getPdo')->once()->andReturn($pdo);
        $database->shouldReceive('unprepared')
            ->once()
            ->with('SET SESSION max_statement_time = 3.000')
            ->andReturn(true);

        $this->assertTrue(app(MySqlSessionQueryTimeout::class)->apply($database));
    }

    public function test_timeout_configuration_failure_is_non_fatal(): void
    {
        config()->set('cms.external_database.query_timeout_ms', 3000);

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')
            ->once()
            ->with(PDO::ATTR_SERVER_VERSION)
            ->andReturn('8.0.42');

        $database = Mockery::mock(Connection::class);
        $database->shouldReceive('getPdo')->once()->andReturn($pdo);
        $database->shouldReceive('unprepared')
            ->once()
            ->with('SET SESSION max_execution_time = 3000')
            ->andThrow(new RuntimeException('Unknown system variable.'));

        $this->assertFalse(app(MySqlSessionQueryTimeout::class)->apply($database));
    }

    public function test_unsupported_server_does_not_receive_an_invalid_statement(): void
    {
        $timeout = new MySqlSessionQueryTimeout;

        $this->assertNull($timeout->statementFor('5.6.51', 3000));
        $this->assertNull($timeout->statementFor('unknown', 3000));
    }

    public function test_timeout_is_clamped_to_safe_bounds(): void
    {
        $timeout = new MySqlSessionQueryTimeout;

        $this->assertSame('SET SESSION max_execution_time = 100', $timeout->statementFor('8.0.42', 1));
        $this->assertSame('SET SESSION max_execution_time = 30000', $timeout->statementFor('8.0.42', 90000));
    }
}
