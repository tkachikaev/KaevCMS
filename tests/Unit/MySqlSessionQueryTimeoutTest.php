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
            ->with('SET SESSION innodb_lock_wait_timeout = 10')
            ->andReturn(true);
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
            ->with('SET SESSION innodb_lock_wait_timeout = 10')
            ->andThrow(new RuntimeException('Unknown system variable.'));
        $database->shouldReceive('unprepared')
            ->once()
            ->with('SET SESSION max_execution_time = 3000')
            ->andThrow(new RuntimeException('Unknown system variable.'));

        $this->assertFalse(app(MySqlSessionQueryTimeout::class)->apply($database));
    }

    public function test_connection_is_opened_with_a_bounded_network_read_timeout_that_is_then_restored(): void
    {
        if (ini_get('mysqlnd.net_read_timeout') === false) {
            $this->markTestSkipped('mysqlnd is not available.');
        }

        config()->set('cms.external_database.read_timeout_seconds', 20);
        $before = ini_get('mysqlnd.net_read_timeout');

        $during = (new MySqlSessionQueryTimeout)->withNetworkReadTimeout(
            static fn (): string|false => ini_get('mysqlnd.net_read_timeout'),
        );

        $this->assertSame('20', $during);
        $this->assertSame($before, ini_get('mysqlnd.net_read_timeout'));
    }

    public function test_network_read_timeout_is_restored_when_connecting_fails(): void
    {
        if (ini_get('mysqlnd.net_read_timeout') === false) {
            $this->markTestSkipped('mysqlnd is not available.');
        }

        $before = ini_get('mysqlnd.net_read_timeout');

        try {
            (new MySqlSessionQueryTimeout)->withNetworkReadTimeout(static function (): never {
                throw new RuntimeException('Connection refused.');
            });
            $this->fail('The connection failure must propagate.');
        } catch (RuntimeException) {
            $this->assertSame($before, ini_get('mysqlnd.net_read_timeout'));
        }
    }

    public function test_row_lock_wait_always_ends_before_the_network_read_timeout(): void
    {
        $timeout = new MySqlSessionQueryTimeout;

        config()->set('cms.external_database.read_timeout_seconds', 20);
        config()->set('cms.external_database.lock_wait_timeout_seconds', 10);
        $this->assertSame('SET SESSION innodb_lock_wait_timeout = 10', $timeout->lockWaitStatement());

        // A misconfigured lock wait longer than the read timeout is capped below it.
        config()->set('cms.external_database.lock_wait_timeout_seconds', 120);
        $this->assertSame(15, $timeout->lockWaitSeconds());

        config()->set('cms.external_database.read_timeout_seconds', 1);
        $this->assertSame(5, $timeout->readTimeoutSeconds());
        $this->assertSame(1, $timeout->lockWaitSeconds());
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
