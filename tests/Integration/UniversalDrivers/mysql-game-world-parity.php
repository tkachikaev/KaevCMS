<?php

/**
 * Executed inside mysql-fixtures.php against unique, disposable local DBs.
 * The production GameWorldDriverResolver and real server connections are untouched.
 */
if (getenv('KAEV_UNIVERSAL_MYSQL_TEST') !== '1' || ! isset($pdo, $databaseNames)) {
    throw new RuntimeException('World parity needs isolated test databases.');
}
require_once __DIR__.'/../../../vendor/autoload.php';

use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\GameWorld\MobiusGameWorldDriver;
use App\Services\GameWorld\RusacisGameSchemaInspector;
use App\Services\GameWorld\RusacisGameWorldDriver;
use App\Services\GameWorld\UniversalGameWorldReader;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;

// Legacy RUSaCis castle names pass through __(); install a no-op translator
// for this isolated test rather than booting a CMS installation or .env.
$container = new Container;
$container->instance('translator', new class
{
    public function get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string
    {
        foreach ($replace as $name => $value) {
            $key = str_replace(':'.$name, (string) $value, $key);
        }

        return $key;
    }
});
Container::setInstance($container);
$profiles = (new BundledProfileCatalog)->all();
$reader = new UniversalGameWorldReader;

foreach ($databaseNames as $type => $db) {
    $pdo->exec("USE `$db`");
    $connection = new MySqlConnection($pdo, $db, '', ['driver' => 'mysql', 'database' => $db, 'prefix' => '']);
    $gateway = new class($connection) implements GameServerDatabaseGateway
    {
        public function __construct(private readonly Connection $connection) {}

        public function run(GameServer $server, callable $callback): mixed
        {
            return $callback($this->connection);
        }
    };
    $legacy = $type === 'rusacis'
        ? new RusacisGameWorldDriver($gateway, new RusacisGameSchemaInspector)
        : new MobiusGameWorldDriver($gateway, new MobiusGameSchemaInspector);
    $server = new GameServer;
    $server->chronicle = 'Interlude';
    $profile = $profiles['game:'.$type];
    $report = $reader->diagnose($connection, $profile);
    if ($report !== ['missing_required' => [], 'missing_optional' => []]) {
        throw new RuntimeException("Unexpected schema diagnostics: $type");
    }
    if ($reader->capabilities($connection, $profile) !== $legacy->capabilities($server)) {
        throw new RuntimeException("Capability parity failed for $type");
    }
    foreach (['level', 'pvp', 'pk', 'play_time'] as $section) {
        foreach ([1, 20, 500] as $limit) {
            $old = $legacy->ranking($server, $section, $limit);
            $new = $reader->ranking($connection, $profile, $section, $limit, 'Interlude');
            assertWorldParity($old, $new, "$type ranking $section/$limit");
        }
    }
    assertWorldParity($legacy->heroes($server), $reader->heroes($connection, $profile, 'Interlude'), "$type heroes");
    assertWorldParity($legacy->castleOwners($server), $reader->castleOwners($connection, $profile), "$type castles");

    // Unknown castle IDs must keep the same translated fallback as the old
    // RUSaCis driver instead of inventing an empty/incorrect castle name.
    if ($type === 'rusacis') {
        $pdo->exec('INSERT INTO castle(id) VALUES (99)');
        $pdo->exec('UPDATE clan_data SET hasCastle = 99 WHERE clan_id = 7');
        assertWorldParity($legacy->castleOwners($server), $reader->castleOwners($connection, $profile), 'RUSaCis unknown castle ID');
        $pdo->exec('UPDATE clan_data SET hasCastle = 1 WHERE clan_id = 7');
        $pdo->exec('DELETE FROM castle WHERE id = 99');
    }

    // Optional hero/castle data may be absent without disabling rankings.
    $pdo->exec('DROP TABLE heroes');
    $pdo->exec('DROP TABLE castle');
    $capabilities = $reader->capabilities($connection, $profile);
    if (in_array('heroes', $capabilities, true) || in_array('castles', $capabilities, true)) {
        throw new RuntimeException("Absent optional world features were advertised for $type");
    }
    if ($reader->heroes($connection, $profile) !== [] || $reader->castleOwners($connection, $profile) !== []) {
        throw new RuntimeException("Absent optional world features were not disabled for $type");
    }
    assertWorldParity($legacy->ranking($server, 'level', 10), $reader->ranking($connection, $profile, 'level', 10, 'Interlude'), "$type ranking with optional tables missing");

    // The validated profile, not a hardcoded PHP driver, selects the table.
    // Only an isolated test table is created (no actual game DB touched).
    $pdo->exec('CREATE TABLE world_heroes (hero_id BIGINT, hero_count BIGINT, hero_played BIGINT, hero_claimed BIGINT) ENGINE=InnoDB');
    $id = $type === 'rusacis' ? 102 : 202;
    $pdo->exec("INSERT INTO world_heroes VALUES ($id, 1, 1, 1)");
    $custom = $profile;
    foreach ($custom['config']['requirements'] as &$requirement) {
        if ($requirement['table'] === 'heroes') {
            $requirement['table'] = 'world_heroes';
            $requirement['columns'] = ['hero_id', 'hero_count', 'hero_played', 'hero_claimed'];
        }
    }
    unset($requirement);
    $custom['config']['relations']['heroes']['table'] = 'world_heroes';
    $custom['config']['relations']['heroes']['foreign_column'] = 'hero_id';
    $custom['config']['features']['heroes'] = [
        'relation' => 'heroes', 'count_column' => 'hero_count', 'played_column' => 'hero_played',
        'claimed_column' => 'hero_claimed',
        ...($type === 'rusacis' ? ['active_column' => 'hero_claimed', 'played_value' => 1, 'active_value' => 1] : []),
    ];
    if (count($reader->heroes($connection, $custom)) !== 1) {
        throw new RuntimeException("Custom JSON heroes table/column mapping failed for $type");
    }
    $pdo->exec('DROP TABLE world_heroes');

    $pdo->exec('CREATE TABLE world_castles (world_id BIGINT, world_name VARCHAR(60)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO world_castles VALUES (1, 'Gludio Castle')");
    $customCastle = $profile;
    foreach ($customCastle['config']['requirements'] as &$requirement) {
        if ($requirement['table'] === 'castle') {
            $requirement['table'] = 'world_castles';
            $requirement['columns'] = ['world_id', 'world_name'];
        }
    }
    unset($requirement);
    $customCastle['config']['relations']['castle']['table'] = 'world_castles';
    $customCastle['config']['relations']['castle']['foreign_column'] = 'world_id';
    $customCastle['config']['features']['castles']['name_source'] = 'column';
    $customCastle['config']['features']['castles']['name_column'] = 'world_name';
    $owners = $reader->castleOwners($connection, $customCastle);
    if (count($owners) !== 1 || $owners[0]['castle_name'] !== 'Gludio Castle') {
        throw new RuntimeException("Custom JSON castle mapping failed for $type");
    }
    $pdo->exec('DROP TABLE world_castles');

    // Duplicate single-row relations must fail closed instead of silently
    // inflating a ranking. Also check hostile SQL identifier import.
    $pdo->exec("INSERT INTO clan_data(clan_id, clan_name, clan_level, reputation_score, hasCastle, leader_id) VALUES (7, 'Duplicate', 8, 0, 0, 0)");
    try {
        $reader->ranking($connection, $profile, 'level', 100);
        throw new RuntimeException('Duplicate clan relation silently inflated a ranking.');
    } catch (ProfileValidationException) {
        // Expected.
    }
    $pdo->exec("DELETE FROM clan_data WHERE clan_name = 'Duplicate'");
    $malicious = $profile;
    $malicious['config']['rankings']['pvp']['column'] = 'pvpkills DESC; DROP TABLE characters';
    try {
        $reader->ranking($connection, $malicious, 'pvp', 100);
        throw new RuntimeException('Unsafe ranking SQL identifier was accepted.');
    } catch (ProfileValidationException) {
        // Expected.
    }
    echo "OK: $type rankings, heroes, castles, missing optional schema and custom JSON mapping\n";
}

/** @param list<array<string,mixed>> $old
 * @param  list<array<string,mixed>>  $new
 */
function assertWorldParity(array $old, array $new, string $context): void
{
    if (count($old) !== count($new)) {
        throw new RuntimeException("World count parity failed: $context (".count($old).' != '.count($new).')');
    }
    foreach ($old as $i => $row) {
        foreach ($row as $field => $value) {
            if (! array_key_exists($field, $new[$i]) || (string) ($new[$i][$field] ?? '') !== (string) ($value ?? '')) {
                throw new RuntimeException("World field parity failed: $context row $i / $field");
            }
        }
    }
}
