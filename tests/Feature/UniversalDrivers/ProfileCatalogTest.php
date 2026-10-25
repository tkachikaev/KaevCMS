<?php

namespace Tests\Feature\UniversalDrivers;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ProfileCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundled_profiles_are_available_without_copying_them_into_database(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $this->assertCount(5, $catalog->all());
        $this->assertSame('builtin', $catalog->find('login', 'rusacis')['origin']);
        $this->assertFalse($catalog->find('login', 'rusacis')['modified']);
        $this->assertSame(0, DB::table('universal_driver_profiles')->count());
    }

    public function test_editing_builtin_preserves_original_file_and_restore_reverts_override(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $before = (new BundledProfileCatalog)->all()['login:rusacis'];
        $override = $before;
        $override['name'] = 'My RUSaCis';
        $catalog->save($override);
        $this->assertSame('My RUSaCis', $catalog->find('login', 'rusacis')['profile']['name']);
        $this->assertTrue($catalog->find('login', 'rusacis')['modified']);
        $this->assertSame($before, (new BundledProfileCatalog)->all()['login:rusacis']);
        $catalog->restoreBuiltin('login', 'rusacis');
        $this->assertSame($before, $catalog->find('login', 'rusacis')['profile']);
        $this->assertSame(0, DB::table('universal_driver_profiles')->count());
    }

    public function test_deleted_builtin_stays_hidden_even_when_original_file_remains_present(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $catalog->delete('login', 'rusacis');
        $this->assertNull($catalog->find('login', 'rusacis'));
        $this->assertArrayHasKey('login:rusacis', (new BundledProfileCatalog)->all());
        $this->assertSame(1, DB::table('universal_driver_profiles')->where('is_deleted', true)->count());
        $catalog->restoreBuiltin('login', 'rusacis');
        $this->assertNotNull($catalog->find('login', 'rusacis'));
    }

    public function test_import_export_roundtrip_and_conflicts_do_not_overwrite_existing_entries(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $original = (new BundledProfileCatalog)->all()['login:rusacis'];
        $imported = $catalog->import(json_encode($original, JSON_THROW_ON_ERROR));
        $this->assertSame('rusacis-2', $imported['id']);
        $this->assertSame('RUSaCis LoginServer (2)', $imported['name']);
        $again = $catalog->import(json_encode($original, JSON_THROW_ON_ERROR));
        $this->assertSame('rusacis-3', $again['id']);
        $this->assertSame($original, $catalog->find('login', 'rusacis')['profile']);
        $this->assertSame($imported, json_decode($catalog->export('login', 'rusacis-2'), true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('database_host', $catalog->export('login', 'rusacis-2'));
        $catalog->delete('login', 'rusacis-2');
        $this->assertNull($catalog->find('login', 'rusacis-2'));
    }

    public function test_import_preserves_type_and_renames_duplicate_name_even_when_id_is_different(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $profile = (new BundledProfileCatalog)->all()['login:rusacis'];
        $profile['id'] = 'custom-rusacis';
        $new = $catalog->import(json_encode($profile, JSON_THROW_ON_ERROR));
        $this->assertSame('custom-rusacis-2', $new['id']);
        $this->assertSame('RUSaCis LoginServer (2)', $new['name']);
    }

    public function test_cannot_delete_profile_used_by_existing_login_or_game_server(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        LoginServer::factory()->create(['driver' => 'rusacis']);
        $this->expectException(ProfileValidationException::class);
        $catalog->delete('login', 'rusacis');
    }

    public function test_game_server_uses_separate_type_namespace_when_protecting_deletion(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        GameServer::factory()->create(['driver' => 'rusacis']);
        $this->expectException(ProfileValidationException::class);
        $catalog->delete('game', 'rusacis');
    }

    public function test_corrupt_or_unsupported_json_cannot_be_imported(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $profile = (new BundledProfileCatalog)->all()['login:rusacis'];
        $profile['config']['accounts']['sql'] = 'DELETE FROM accounts';
        $this->expectException(ProfileValidationException::class);
        $catalog->import(json_encode($profile, JSON_THROW_ON_ERROR));
    }

    public function test_hidden_builtin_identity_is_not_reused_on_import(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $original = (new BundledProfileCatalog)->all()['login:rusacis'];
        $catalog->delete('login', 'rusacis');
        $new = $catalog->import(json_encode($original, JSON_THROW_ON_ERROR));
        $this->assertSame('rusacis-2', $new['id']);
        $this->assertNull($catalog->find('login', 'rusacis'));
    }

    public function test_unverified_or_failed_revalidation_cannot_overwrite_verified_snapshot(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $snapshots = app(ConnectionProfileSnapshots::class);
        $server = LoginServer::factory()->create(['driver' => 'rusacis']);
        $this->assertNull($snapshots->get($server));
        $calls = 0;
        $snapshots->verifyAndRecord($server, 'rusacis', function ($connection, $profile) use (&$calls): bool {
            $calls++;
            return $connection->getKey() > 0 && $profile['type'] === 'login';
        });
        $this->assertSame(1, $calls);
        $original = $snapshots->get($server);
        $new = $catalog->find('login', 'rusacis')['profile'];
        $new['name'] = 'Revised mapping';
        $catalog->save($new);
        $this->assertSame($original, $snapshots->get($server));
        try {
            $snapshots->verifyAndRecord($server, 'rusacis', static fn (): bool => false);
            $this->fail('Unverified update succeeded');
        } catch (ProfileValidationException) {
            $this->assertSame($original, $snapshots->get($server));
        }
        $snapshots->verifyAndRecord($server, 'rusacis', static fn (): bool => true);
        $this->assertSame('Revised mapping', $snapshots->get($server)['name']);
    }

    public function test_snapshot_integrity_corruption_fails_closed_and_never_falls_back_to_current_profile(): void
    {
        $snapshots = app(ConnectionProfileSnapshots::class);
        $server = LoginServer::factory()->create(['driver' => 'rusacis']);
        $snapshots->verifyAndRecord($server, 'rusacis', static fn (): bool => true);
        DB::table('universal_driver_connection_snapshots')
            ->where('connection_type', 'login')->where('connection_id', $server->id)
            ->update(['profile_json' => '{"invalid":"snapshot"}']);
        $this->expectException(RuntimeException::class);
        $snapshots->get($server);
    }


    public function test_game_connection_snapshot_is_independent_from_login_connection_namespace(): void
    {
        $snapshots = app(ConnectionProfileSnapshots::class);
        $login = LoginServer::factory()->create(['driver' => 'rusacis']);
        $game = GameServer::factory()->for($login)->create(['driver' => 'rusacis']);
        $snapshots->verifyAndRecord($login, 'rusacis', static fn (): bool => true);
        $snapshots->verifyAndRecord($game, 'rusacis', static fn (): bool => true);
        $this->assertSame('login', $snapshots->get($login)['type']);
        $this->assertSame('game', $snapshots->get($game)['type']);
        $this->assertSame(2, DB::table('universal_driver_connection_snapshots')->count());
    }

    public function test_verified_snapshot_rejects_driver_change_after_verification(): void
    {
        $snapshots = app(ConnectionProfileSnapshots::class);
        $login = LoginServer::factory()->create(['driver' => 'rusacis']);
        $snapshots->verifyAndRecord($login, 'rusacis', static fn (): bool => true);
        $login->update(['driver' => 'l2j_mobius']);
        $this->expectException(RuntimeException::class);
        $snapshots->get($login);
    }

    public function test_snapshot_for_old_driver_cannot_be_replaced_with_different_identity_without_switch(): void
    {
        $snapshots = app(ConnectionProfileSnapshots::class);
        $server = LoginServer::factory()->create(['driver' => 'rusacis']);
        $this->expectException(ProfileValidationException::class);
        $snapshots->verifyAndRecord($server, 'l2j_mobius', static fn (): bool => true);
    }
}
