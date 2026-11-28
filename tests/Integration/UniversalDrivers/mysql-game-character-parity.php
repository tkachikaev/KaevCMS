<?php

/**
 * Run only from mysql-fixtures.php against the disposable MySQL databases.
 * No production connection or write path is exercised.
 */
if (getenv('KAEV_UNIVERSAL_MYSQL_TEST') !== '1' || ! isset($pdo, $databaseNames)) {
    throw new RuntimeException('GameServer parity requires isolated MySQL fixtures.');
}
$autoload = __DIR__.'/../../../vendor/autoload.php';
if (! is_file($autoload)) {
    throw new RuntimeException('Composer dependencies are required for GameServer parity.');
}
require_once $autoload;

use App\Services\GameWorld\UniversalGameCharacterReader;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Database\MySqlConnection;

$profiles = (new BundledProfileCatalog)->all();
$reader = new UniversalGameCharacterReader;
foreach ($databaseNames as $type => $db) {
    $pdo->exec("USE `$db`");
    $connection = new MySqlConnection($pdo, $db, '', [
        'driver' => 'mysql', 'database' => $db, 'prefix' => '',
    ]);
    $profile = $profiles['game:'.$type];
    // An empty alternatives list is not a requirement. Both readers must
    // keep the same core schema available.
    $emptyAlternatives = $profile;
    foreach ($emptyAlternatives['config']['requirements'] as &$requirement) {
        if ($requirement['table'] === $profile['config']['characters']['table']) {
            $requirement['any_columns'] = [];
        }
    }
    unset($requirement);
    $emptyAlternatives['config']['requirements'][] = [
        'table' => 'fixture_missing_optional', 'columns' => [], 'any_columns' => [], 'required' => false,
    ];
    if (count($reader->charactersForAccount($connection, $emptyAlternatives, 'alice', 1)) !== 1) {
        throw new RuntimeException('An empty alternatives list or missing optional table blocked character lookup.');
    }
    if ($reader->diagnose($connection, $profile) !== ['missing_required' => [], 'missing_optional' => []]) {
        throw new RuntimeException("Unexpected game fixture schema gaps for $type.");
    }
    foreach (['alice' => ['AliceKnight'], 'bob' => ['BobMage'], 'absent' => [], "alice' OR 1=1 --" => []] as $login => $expected) {
        $rows = $reader->charactersForAccount($connection, $profile, $login, 100, 'Interlude');
        if (array_column($rows, 'name') !== $expected) {
            throw new RuntimeException("Character ownership/filter lookup failed for $type/$login.");
        }
    }
    $alice = $reader->charactersForAccount($connection, $profile, 'alice', 1, 'Interlude')[0];
    $bob = $reader->charactersForAccount($connection, $profile, 'bob', 1, 'Interlude')[0];
    if ((int) $alice['id'] !== ($type === 'rusacis' ? 101 : 201)
        || (int) $alice['pvp_kills'] !== 10 || $alice['clan_name'] !== 'TestClan'
        || (int) $bob['hero'] !== 1) {
        throw new RuntimeException("Character field mapping failed for $type.");
    }
    if ($type === 'l2j_mobius') {
        $modern = $reader->charactersForAccount($connection, $profile, 'bob', 1, 'Essence')[0];
        if ((int) $modern['reputation'] !== 9 || (int) $bob['reputation'] !== 0) {
            throw new RuntimeException('Mobius chronicle did not select the correct reputation column.');
        }
    }

    // A numeric ownership field cannot be queried as a login. MySQL may
    // otherwise coerce arbitrary non-numeric names to the same numeric value.
    $wrongOwner = $profile;
    $wrongOwner['config']['characters']['owner']['column'] = $profile['config']['characters']['fields']['id']['column'];
    try {
        $reader->charactersForAccount($connection, $wrongOwner, 'alice');
        throw new RuntimeException('Numeric character ownership column was accepted as a login.');
    } catch (ProfileValidationException) {
        // Expected.
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
    $innerEav = $profileEav;
    $innerEav['config']['relations']['stats']['join'] = 'inner';
    foreach ($innerEav['config']['requirements'] as &$requirement) {
        if ($requirement['table'] === 'custom_char_stats') {
            $requirement['required'] = true;
        }
    }
    unset($requirement);
    if (count($reader->charactersForAccount($connection, $innerEav, 'alice')) !== 1
        || $reader->charactersForAccount($connection, $innerEav, 'bob') !== []) {
        throw new RuntimeException('Required EAV key did not restrict character eligibility.');
    }
    $pdo->exec("INSERT INTO custom_char_stats VALUES ($id, 'level', '99')");
    try {
        $reader->charactersForAccount($connection, $profileEav, 'alice');
        throw new RuntimeException('Ambiguous EAV value was silently accepted.');
    } catch (ProfileValidationException) {
        // Expected.
    }
    // A partially available optional EAV source must not hide base characters.
    $pdo->exec('ALTER TABLE custom_char_stats DROP COLUMN stat_value');
    $rows = $reader->charactersForAccount($connection, $profileEav, 'alice');
    if (count($rows) !== 1 || $rows[0]['level'] !== null) {
        throw new RuntimeException('Missing optional EAV value column must not hide characters.');
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
    echo "OK: $type GameServer character lookup, ownership, mappings, EAV and injection\n";
}
