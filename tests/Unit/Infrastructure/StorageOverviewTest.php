<?php

namespace Tests\Unit\Infrastructure;

use App\Services\Infrastructure\StorageOverview;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StorageOverviewTest extends TestCase
{
    private ?string $sqlitePath = null;

    protected function tearDown(): void
    {
        if ($this->sqlitePath !== null) {
            DB::purge('storage_overview_test');
            @unlink($this->sqlitePath);
            @unlink($this->sqlitePath.'-wal');
            @unlink($this->sqlitePath.'-shm');
        }

        parent::tearDown();
    }

    public function test_mysql_statistics_are_collected_without_exposing_connection_credentials(): void
    {
        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.database' => 'private_database_name',
            'database.connections.mysql.username' => 'private_database_user',
            'database.connections.mysql.password' => 'private_database_password',
        ]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')->once()->with('select 1')->andReturn([(object) ['value' => 1]]);
        $connection->shouldReceive('getPdo')->once()->andThrow(new RuntimeException('Version is unavailable'));
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(fn (string $query): bool => str_contains($query, 'information_schema.TABLES')))
            ->andReturn((object) [
                'table_count' => '18',
                'data_bytes' => '10485760',
                'index_bytes' => '2097152',
            ]);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with('mysql')->andReturn($connection);

        $overview = (new StorageOverview($database))->collect();
        $databaseOverview = $overview['database'];
        $encoded = json_encode($databaseOverview, JSON_THROW_ON_ERROR);

        $this->assertTrue($databaseOverview['connected']);
        $this->assertSame('MySQL', $databaseOverview['driver_label']);
        $this->assertSame(12_582_912, $databaseOverview['total_bytes']);
        $this->assertSame(10_485_760, $databaseOverview['data_bytes']);
        $this->assertSame(2_097_152, $databaseOverview['index_bytes']);
        $this->assertSame(18, $databaseOverview['table_count']);
        $this->assertStringNotContainsString('private_database_name', $encoded);
        $this->assertStringNotContainsString('private_database_user', $encoded);
        $this->assertStringNotContainsString('private_database_password', $encoded);
    }

    public function test_sqlite_file_reports_total_size_and_table_count(): void
    {
        $this->sqlitePath = storage_path('framework/testing/storage-overview-'.bin2hex(random_bytes(6)).'.sqlite');
        if (! is_dir(dirname($this->sqlitePath))) {
            mkdir(dirname($this->sqlitePath), 0755, true);
        }
        touch($this->sqlitePath);

        config()->set([
            'database.default' => 'storage_overview_test',
            'database.connections.storage_overview_test' => [
                'driver' => 'sqlite',
                'database' => $this->sqlitePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
            ],
        ]);

        DB::purge('storage_overview_test');
        $connection = DB::connection('storage_overview_test');
        $connection->statement('CREATE TABLE storage_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $connection->statement('CREATE INDEX storage_items_name_index ON storage_items (name)');
        $connection->table('storage_items')->insert([
            ['name' => str_repeat('A', 1024)],
            ['name' => str_repeat('B', 1024)],
        ]);
        clearstatcache(true, $this->sqlitePath);

        $overview = app(StorageOverview::class)->collect()['database'];

        $this->assertTrue($overview['connected']);
        $this->assertSame('SQLite', $overview['driver_label']);
        $this->assertTrue($overview['statistics_available']);
        $this->assertSame(1, $overview['table_count']);
        $this->assertIsInt($overview['total_bytes']);
        $this->assertGreaterThan(0, $overview['total_bytes']);
        $this->assertNotNull($overview['total']);
    }

    public function test_unavailable_mysql_statistics_do_not_break_connected_database_status(): void
    {
        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
        ]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')->once()->with('select 1')->andReturn([(object) ['value' => 1]]);
        $connection->shouldReceive('getPdo')->once()->andThrow(new RuntimeException('Version is unavailable'));
        $connection->shouldReceive('selectOne')->once()->andThrow(new RuntimeException('Permission denied'));

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with('mysql')->andReturn($connection);

        $overview = (new StorageOverview($database))->collect()['database'];

        $this->assertTrue($overview['connected']);
        $this->assertFalse($overview['statistics_available']);
        $this->assertNull($overview['total_bytes']);
        $this->assertNull($overview['data_bytes']);
        $this->assertNull($overview['index_bytes']);
        $this->assertNull($overview['table_count']);
    }
}
