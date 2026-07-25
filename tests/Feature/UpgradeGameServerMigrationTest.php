<?php

namespace Tests\Feature;

use App\Models\CmsSetting;
use App\Models\GameServer;
use App\Models\LoginServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UpgradeGameServerMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_status_migration_preserves_existing_servers(): void
    {
        $migration = require database_path('migrations/2026_07_17_000200_add_database_status_to_servers.php');
        $migration->down();

        $loginServer = LoginServer::query()->create([
            'name' => 'Existing LoginServer',
            'driver' => 'l2j_mobius',
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'login',
            'database_username' => 'cms',
            'database_password' => 'secret',
            'database_charset' => 'utf8mb4',
        ]);
        $gameServer = GameServer::query()->create([
            'name' => 'Existing GameServer',
            'sort_order' => 0,
            'login_server_id' => $loginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => true,
        ]);

        $migration->up();

        $this->assertDatabaseHas('login_servers', [
            'id' => $loginServer->id,
            'name' => 'Existing LoginServer',
            'database_status' => 'unknown',
        ]);
        $this->assertDatabaseHas('game_servers', [
            'id' => $gameServer->id,
            'name' => 'Existing GameServer',
            'database_status' => 'unknown',
        ]);
    }

    public function test_maintenance_migration_preserves_existing_game_servers_and_translations(): void
    {
        $simplification = require database_path('migrations/2026_07_17_000400_remove_game_server_maintenance_until.php');
        $simplification->down();

        $migration = require database_path('migrations/2026_07_17_000300_add_game_server_maintenance_fields.php');
        $migration->down();

        $gameServer = GameServer::query()->create([
            'name' => 'Existing GameServer',
            'sort_order' => 0,
        ]);
        $gameServer->translations()->create([
            'locale' => 'ru',
            'name' => 'Существующий сервер',
        ]);

        $migration->up();
        $simplification->up();

        $this->assertDatabaseHas('game_servers', [
            'id' => $gameServer->id,
            'name' => 'Existing GameServer',
            'maintenance_enabled' => 0,
        ]);
        $this->assertDatabaseHas('game_server_translations', [
            'game_server_id' => $gameServer->id,
            'locale' => 'ru',
            'name' => 'Существующий сервер',
            'maintenance_message' => null,
        ]);
        $this->assertFalse(Schema::hasColumn('game_servers', 'maintenance_until'));
    }

    public function test_maintenance_simplification_removes_only_the_unused_end_time(): void
    {
        $migration = require database_path('migrations/2026_07_17_000400_remove_game_server_maintenance_until.php');
        $migration->down();

        $gameServer = GameServer::query()->firstOrFail();
        $gameServer->update(['maintenance_enabled' => true]);
        DB::table('game_servers')
            ->where('id', $gameServer->id)
            ->update(['maintenance_until' => now()->addHour()]);
        $gameServer->translations()->updateOrCreate(
            ['locale' => 'ru'],
            ['name' => 'Interlude', 'maintenance_message' => 'Обслуживание до 15:30 МСК'],
        );

        $migration->up();

        $this->assertFalse(Schema::hasColumn('game_servers', 'maintenance_until'));
        $this->assertDatabaseHas('game_servers', [
            'id' => $gameServer->id,
            'maintenance_enabled' => 1,
        ]);
        $this->assertDatabaseHas('game_server_translations', [
            'game_server_id' => $gameServer->id,
            'locale' => 'ru',
            'maintenance_message' => 'Обслуживание до 15:30 МСК',
        ]);
    }

    public function test_public_statistics_migrations_preserve_servers_and_split_the_existing_limit(): void
    {
        $splitMigration = require database_path('migrations/2026_07_18_000000_split_game_server_statistics_limits.php');
        $splitMigration->down();

        $statisticsMigration = require database_path('migrations/2026_07_17_000500_add_public_statistics_to_game_servers.php');
        $statisticsMigration->down();

        $gameServer = GameServer::query()->create([
            'name' => 'Existing GameServer',
            'sort_order' => 0,
        ]);

        $statisticsMigration->up();
        DB::table('game_servers')
            ->where('id', $gameServer->id)
            ->update(['statistics_limit' => 25]);
        $splitMigration->up();

        $this->assertDatabaseHas('game_servers', [
            'id' => $gameServer->id,
            'name' => 'Existing GameServer',
            'statistics_enabled' => 0,
            'statistics_level_enabled' => 1,
            'statistics_pvp_enabled' => 1,
            'statistics_pk_enabled' => 1,
            'statistics_play_time_enabled' => 1,
            'statistics_heroes_enabled' => 1,
            'statistics_castles_enabled' => 1,
            'statistics_level_limit' => 25,
            'statistics_pvp_limit' => 25,
            'statistics_pk_limit' => 25,
            'statistics_play_time_limit' => 25,
        ]);
        $this->assertFalse(Schema::hasColumn('game_servers', 'statistics_limit'));
    }

    public function test_legacy_071_settings_are_copied_to_the_first_game_server(): void
    {
        Schema::drop('game_servers');

        CmsSetting::query()->updateOrCreate(['key' => 'server.name'], ['value' => 'Legacy x7']);
        CmsSetting::query()->updateOrCreate(['key' => 'server.rates'], ['value' => 'x7']);
        CmsSetting::query()->updateOrCreate(['key' => 'server.chronicle'], ['value' => 'Interlude']);
        CmsSetting::query()->updateOrCreate(['key' => 'server.mode'], ['value' => 'PvP']);

        $migration = require database_path('migrations/2026_07_12_000500_create_game_servers_table.php');
        $migration->up();

        $this->assertDatabaseHas('game_servers', [
            'name' => 'Legacy x7',
            'rates' => 'x7',
            'chronicle' => 'Interlude',
            'mode' => 'PvP',
            'sort_order' => 0,
        ]);
    }
}
