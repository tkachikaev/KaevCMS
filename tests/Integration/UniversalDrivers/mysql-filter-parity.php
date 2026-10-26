<?php

/** Runs only inside the disposable localhost fixture harness. */
if (getenv('KAEV_UNIVERSAL_MYSQL_TEST') !== '1' || ! isset($pdo, $databaseNames, $created)
    || array_values($databaseNames) !== $created) {
    throw new RuntimeException('Related filter parity requires process-owned test databases.');
}

use App\Services\GameWorld\UniversalGameCharacterReader;
use App\Services\GameWorld\UniversalGameWorldReader;
use App\Services\Servers\UniversalProfileSchemaProbe;
use App\Support\UniversalDrivers\GameProfileEditor;
use App\Support\UniversalDrivers\ProfileEditorDraft;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
use Illuminate\Database\MySqlConnection;

(static function (PDO $pdo, array $databases): void {
    foreach ($databases as $family => $database) {
        $pdo->exec('USE `'.$database.'`');
        $pdo->exec('CREATE TABLE filter_chars (charId INT PRIMARY KEY, account_name VARCHAR(40), char_name VARCHAR(40), level INT, exp BIGINT, deletetime BIGINT NULL)');
        $pdo->exec('CREATE TABLE filter_flags (char_id INT, kind VARCHAR(40), value INT NULL, INDEX(char_id))');
        $pdo->exec("INSERT INTO filter_chars VALUES (1,'alice','Alice',80,500,0),(2,'alice','GM',99,900,0),(3,'bob','Bob',80,600,NULL),(4,'alice','Missing',70,100,0),(5,'alice','Deleted',85,300,123)");
        $pdo->exec("INSERT INTO filter_flags VALUES (1,'access',0),(1,'access',0),(2,'access',127),(2,'other',0),(3,'access',0),(5,'access',0)");
        $profile = (new ProfileEditorDraft)->blank('game');
        $profile['id'] = 'filter-test';
        $profile['name'] = 'Filter test';
        $profile['config']['characters']['table'] = 'filter_chars';
        $profile['config']['characters']['fields']['level'] = ['kind' => 'column', 'column' => 'level'];
        $profile['config']['characters']['fields']['experience'] = ['kind' => 'column', 'column' => 'exp'];
        $profile['config']['rankings']['level'] = ['column' => 'level', 'secondary' => 'exp', 'positive_only' => false];
        $link = ['local_column' => 'charId', 'foreign_column' => 'char_id'];
        $profile['config']['filters'] = [
            ['column' => 'deletetime', 'op' => 'zero_or_null'],
            ['table' => 'filter_flags', 'column' => 'kind', 'op' => 'eq', 'value' => 'access', 'link' => $link],
            ['table' => 'filter_flags', 'column' => 'value', 'op' => 'eq', 'value' => 0, 'link' => $link],
        ];
        $profile = (new GameProfileEditor)->prepare($profile, $profile['config']);
        $validator = new ProfileValidator;
        $validator->validate($profile);
        $checks = 0;
        $check = static function (bool $ok, string $message) use (&$checks): void {
            $checks++;
            if (! $ok) {
                throw new RuntimeException('Related filter parity: '.$message);
            }
        };
        $db = new MySqlConnection($pdo, $database, '', ['driver' => 'mysql', 'database' => $database, 'prefix' => '']);
        $characters = new UniversalGameCharacterReader;
        $world = new UniversalGameWorldReader;
        $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'id'));
        $check($validator->fromJson(json_encode($profile, JSON_THROW_ON_ERROR)) === $profile, 'JSON roundtrip');
        $check((new UniversalProfileSchemaProbe)->problems($db, $profile) === [], 'schema preflight');
        $check($ids($characters->charactersForAccount($db, $profile, 'alice')) === [1], 'account filters, same row and no duplicates');
        $check($ids($characters->charactersForAccount($db, $profile, 'bob')) === [3], 'other account isolation');
        $check($characters->charactersForAccount($db, $profile, "alice' OR 1=1 --") === [], 'owner injection');
        $check($ids($world->ranking($db, $profile, 'level', 10)) === [3, 1], 'ranking filters, experience tie-break and no duplicates');
        $injected = $profile;
        $injected['config']['filters'][1]['value'] = "access' OR 1=1 --";
        $check($world->ranking($db, $injected, 'level', 10) === [], 'bound filter value');
        $legacy = $profile;
        $legacy['config']['filters'] = [$profile['config']['filters'][0]];
        $check($ids($characters->charactersForAccount($db, $legacy, 'alice')) === [2, 1, 4], 'legacy primary-table filter');
        $pdo->exec('ALTER TABLE filter_flags CHANGE char_id broken_id INT');
        $check((new UniversalProfileSchemaProbe)->problems($db, $profile) !== [], 'missing link preflight');
        $check($characters->diagnose($db, $profile)['missing_required'] !== [], 'missing link runtime diagnosis');
        try {
            $world->ranking($db, $profile, 'level', 10);
            throw new RuntimeException('Missing link returned an unfiltered ranking.');
        } catch (ProfileValidationException) {
            $checks++;
        }
        echo "OK: $family related filter SQL parity ($checks assertions)\n";
    }
})($pdo, $databaseNames);
