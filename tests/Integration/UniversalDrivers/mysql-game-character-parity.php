<?php

/**
 * Run only from mysql-fixtures.php against the disposable MySQL databases.
 * No production connection or write path is exercised by the new reader.
 */
if (getenv('KAEV_UNIVERSAL_MYSQL_TEST') !== '1' || ! isset($pdo, $databaseNames)) {
    throw new RuntimeException('GameServer parity requires isolated MySQL fixtures.');
}
$autoload = __DIR__.'/../../../vendor/autoload.php';
if (! is_file($autoload)) {
    throw new RuntimeException('Composer dependencies are required for GameServer parity.');
}
require_once $autoload;

use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\GameWorld\MobiusGameWorldDriver;
use App\Services\GameWorld\RusacisGameSchemaInspector;
use App\Services\GameWorld\RusacisGameWorldDriver;
use App\Services\GameWorld\UniversalGameCharacterReader;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;

$profiles = (new BundledProfileCatalog)->all();
$reader = new UniversalGameCharacterReader;
foreach ($databaseNames as $type => $db) {
    $pdo->exec("USE `$db`");
    $connection = new MySqlConnection($pdo, $db, '', [
        'driver' => 'mysql', 'database' => $db, 'prefix' => '',
    ]);
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
    if ($reader->diagnose($connection, $profile) !== ['missing_required' => [], 'missing_optional' => []]) {
        throw new RuntimeException("Unexpected game fixture schema gaps for $type.");
    }
    foreach (['alice', 'bob', 'absent', "alice' OR 1=1 --"] as $login) {
        $old = $legacy->charactersForAccount($server, $login, 100);
        $new = $reader->charactersForAccount($connection, $profile, $login, 100, 'Interlude');
        if (count($old) !== count($new)) {
            throw new RuntimeException("Character count parity failed for $type/$login.");
        }
        foreach ($old as $i => $row) {
            foreach ($row as $field => $value) {
                if (! array_key_exists($field, $new[$i]) || (string) ($new[$i][$field] ?? '') !== (string) ($value ?? '')) {
                    throw new RuntimeException("Character field parity failed for $type/$login: $field.");
                }
            }
        }
    }
    if ($reader->charactersForAccount($connection, $profile, 'alice', 1)[0]['name'] !== 'AliceKnight') {
        throw new RuntimeException('Character limit/order filtering failed.');
    }
    if ($type === 'l2j_mobius') {
        $server->chronicle = 'Essence';
        $new = $reader->charactersForAccount($connection, $profile, 'bob', 10, 'Essence');
        $old = $legacy->charactersForAccount($server, 'bob');
        if ((string) $new[0]['reputation'] !== (string) $old[0]['reputation']) {
            throw new RuntimeException('Modern Mobius reputation choice differs from legacy.');
        }
    } else {
        $bob = $reader->charactersForAccount($connection, $profile, 'bob');
        if (($bob[0]['hero'] ?? null) !== 1) {
            throw new RuntimeException('RUSaCis active hero lookup failed.');
        }
    }

    $malicious = $profile;
    $malicious['config']['characters']['owner']['column'] = 'account_name` OR 1=1';
    try {
        $reader->charactersForAccount($connection, $malicious, 'alice');
        throw new RuntimeException('Unsafe ownership identifier was accepted.');
    } catch (ProfileValidationException) {
        // Expected.
    }

    // A relation with multiple rows for one character cannot be collapsed
    // silently. Test EAV (many rows with distinct keys) and duplicate-key abort.
    $charId = $type === 'rusacis' ? 'obj_Id' : 'charId';
    $profileEav = $profile;
    $profileEav['config']['requirements'][] = [
        'table' => 'custom_char_stats', 'columns' => ['char_ref', 'stat_key', 'stat_value'], 'required' => false,
    ];
    $profileEav['config']['relations']['stats'] = [
        'table' => 'custom_char_stats', 'local_column' => $charId,
        'foreign_column' => 'char_ref', 'join' => 'left', 'cardinality' => 'many',
    ];
    $profileEav['config']['characters']['fields']['level'] = [
        'kind' => 'eav', 'relation' => 'stats', 'key_column' => 'stat_key',
        'value_column' => 'stat_value', 'key_value' => 'level',
    ];
    $pdo->exec('CREATE TABLE custom_char_stats (char_ref BIGINT NOT NULL, stat_key VARCHAR(40) NOT NULL, stat_value VARCHAR(40) NULL) ENGINE=InnoDB');
    $id = $type === 'rusacis' ? 101 : 201;
    $pdo->exec("INSERT INTO custom_char_stats VALUES ($id, 'level', '85'), ($id, 'unrelated', '999')");
    $eav = $reader->charactersForAccount($connection, $profileEav, 'alice');
    if (count($eav) !== 1 || (string) $eav[0]['level'] !== '85') {
        throw new RuntimeException('EAV mapping failed or joined the wrong key.');
    }
    $pdo->exec("INSERT INTO custom_char_stats VALUES ($id, 'level', '99')");
    try {
        $reader->charactersForAccount($connection, $profileEav, 'alice');
        throw new RuntimeException('Ambiguous EAV value was silently accepted.');
    } catch (ProfileValidationException) {
        // Expected.
    }
    // The EAV schema is explicitly optional: absence cannot break base characters.
    $pdo->exec('DROP TABLE custom_char_stats');
    $diagnostics = $reader->diagnose($connection, $profileEav);
    if (! in_array('table:custom_char_stats', $diagnostics['missing_optional'], true)) {
        throw new RuntimeException('Optional EAV table was not reported.');
    }
    $rows = $reader->charactersForAccount($connection, $profileEav, 'alice');
    if (count($rows) !== 1 || $rows[0]['level'] !== null) {
        throw new RuntimeException('Missing optional EAV table should not conceal or invent a value.');
    }
    echo "OK: $type GameServer read-only character parity, ownership, mappings, EAV and injection\n";
}
