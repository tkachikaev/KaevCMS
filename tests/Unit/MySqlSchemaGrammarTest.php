<?php

namespace Tests\Unit;

use App\Support\Database\MySqlSchemaGrammar;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Fluent;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Old MariaDB/MySQL 5.7 broke the Donations module (tests/Integration/Database/mysql-timestamp-defaults.php).
 */
class MySqlSchemaGrammarTest extends TestCase
{
    public function test_required_timestamps_get_an_explicit_default_only_on_legacy_servers(): void
    {
        $legacy = $this->compile(0, new Fluent(['name' => 'created_at']));
        $this->assertTrue($legacy->useCurrent, 'Old servers must not invent ON UPDATE or a zero date.');

        $modern = $this->compile(1, new Fluent(['name' => 'created_at']));
        $this->assertNull($modern->useCurrent, 'MySQL 8 and MariaDB 10.10+ keep Laravel\'s definition.');
    }

    public function test_nullable_or_defaulted_timestamps_are_left_alone(): void
    {
        $this->assertNull($this->compile(0, new Fluent(['name' => 'sent_at', 'nullable' => true]))->useCurrent);
        $this->assertSame('2026-01-01 00:00:00', $this->compile(0, new Fluent(['name' => 'at', 'default' => '2026-01-01 00:00:00']))->default);
        $this->assertNull($this->compile(0, new Fluent(['name' => 'at', 'default' => '2026-01-01 00:00:00']))->useCurrent);
    }

    public function test_a_server_that_cannot_answer_still_gets_the_explicit_default(): void
    {
        $connection = Mockery::mock(MySqlConnection::class);
        $connection->shouldReceive('selectOne')->once()->andThrow(new RuntimeException('no such variable'));
        $grammar = new MySqlSchemaGrammar($connection);

        $column = new Fluent(['name' => 'created_at']);
        (new ReflectionMethod($grammar, 'typeTimestamp'))->invoke($grammar, $column);
        $second = new Fluent(['name' => 'updated_at']);
        (new ReflectionMethod($grammar, 'typeTimestamp'))->invoke($grammar, $second);

        $this->assertTrue($column->useCurrent);
        $this->assertTrue($second->useCurrent, 'The server is asked once per connection.');
    }

    private function compile(int $explicitDefaults, Fluent $column): Fluent
    {
        $connection = Mockery::mock(MySqlConnection::class);
        $connection->shouldReceive('selectOne')->andReturn((object) ['explicit_defaults' => $explicitDefaults]);
        $grammar = new MySqlSchemaGrammar($connection);

        $type = (new ReflectionMethod($grammar, 'typeTimestamp'))->invoke($grammar, $column);
        $this->assertSame('timestamp', $type);

        return $column;
    }
}
