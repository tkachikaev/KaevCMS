<?php

/** Offline, framework-free regression tests for the read-only activation checks. */

namespace Illuminate\Database {
    class Connection
    {
        public function __construct(private readonly object $schema) {}

        public function getSchemaBuilder(): object
        {
            return $this->schema;
        }
    }
}

namespace {
    use App\Services\Servers\UniversalProfileSchemaProbe;
    use Illuminate\Database\Connection;

    require_once __DIR__.'/../../../app/Support/UniversalDrivers/ProfileFormat.php';
    require_once __DIR__.'/../../../app/Services/Servers/UniversalProfileSchemaProbe.php';

    final class FakeUniversalSchema
    {
        public function __construct(public array $tables, public array $indexes = [], public array $definitions = []) {}

        public function hasTable(string $name): bool
        {
            return isset($this->tables[$name]);
        }

        public function getColumnListing(string $name): array
        {
            return $this->tables[$name] ?? [];
        }

        public function getIndexes(string $name): array
        {
            return $this->indexes[$name] ?? [];
        }

        public function getColumns(string $name): array
        {
            return $this->definitions[$name] ?? [];
        }
    }

    function verify(bool $value, string $case): void
    {
        global $caseCount;
        $caseCount++;
        if (! $value) {
            throw new RuntimeException('Failed: '.$case);
        }
    }

    function issueKeys(array $issues): array
    {
        return array_column($issues, 'key');
    }

    function loadProfile(string $filename): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../../driver-profiles/bundled/'.$filename), true, 512, JSON_THROW_ON_ERROR);
    }

    $caseCount = 0;
    set_error_handler(static function (int $severity, string $message): never {
        throw new RuntimeException($message);
    });
    $probe = new UniversalProfileSchemaProbe;
    $login = loadProfile('login-rusacis.json');
    $table = $login['config']['accounts']['table'];
    $loginColumn = $login['config']['accounts']['fields']['login'];
    $passColumn = $login['config']['accounts']['fields']['password'];
    $schema = new FakeUniversalSchema(
        [$table => [$loginColumn, $passColumn]],
        [$table => [['unique' => true, 'columns' => [$loginColumn]]]],
        [$table => [['name' => $passColumn, 'type' => 'varchar(60)']]],
    );
    verify($probe->problems(new Connection($schema), $login) === [], 'valid login schema');
    $schema->indexes[$table] = [];
    verify(in_array('drivers.schema_unique_login', issueKeys($probe->problems(new Connection($schema), $login)), true), 'missing unique login index');
    $schema->indexes[$table] = [['unique' => true, 'columns' => [$loginColumn]]];
    $schema->definitions[$table][0]['type'] = 'varchar(45)';
    verify(in_array('drivers.schema_password_length', issueKeys($probe->problems(new Connection($schema), $login)), true), 'short bcrypt column');
    $schema->tables[$table] = [$loginColumn];
    verify(in_array('drivers.schema_missing_account_field', issueKeys($probe->problems(new Connection($schema), $login)), true), 'missing password column');

    $readOnly = $login;
    $readOnly['config']['capabilities'] = ['account_lookup'];
    $schema->indexes[$table] = [];
    verify($probe->problems(new Connection($schema), $readOnly) === [], 'read-only profile needs no write index');
    $withoutPassword = $login;
    unset($withoutPassword['config']['accounts']['fields']['password']);
    verify(in_array('drivers.schema_missing_account_field', issueKeys($probe->problems(new Connection($schema), $withoutPassword)), true), 'missing password mapping is a report, not a PHP warning');
    $schema->tables[$table] = [$loginColumn, $passColumn];
    $schema->indexes[$table] = [['unique' => true, 'columns' => [$loginColumn]]];
    $schema->definitions[$table][0]['type'] = 'int';
    verify(in_array('drivers.schema_password_type', issueKeys($probe->problems(new Connection($schema), $login)), true), 'numeric password columns cannot hold a hash');
    $schema->definitions[$table][0]['type'] = 'varchar(60)';
    $registration = $login;
    $registration['config']['accounts']['create']['fixed_values'] = ['missing_flag' => 0];
    verify(in_array('drivers.schema_missing_column', issueKeys($probe->problems(new Connection($schema), $registration)), true), 'registration constants must exist in the real schema');

    $game = loadProfile('game-rusacis.json');
    $tables = [];
    foreach ($game['config']['requirements'] as $requirement) {
        $tables[$requirement['table']] = array_values(array_unique(array_merge(
            $tables[$requirement['table']] ?? [], $requirement['columns'], $requirement['any_columns'] ?? [], $requirement['optional_columns'] ?? [],
        )));
    }
    $gameSchema = new FakeUniversalSchema($tables);
    $problems = $probe->problems(new Connection($gameSchema), $game);
    verify($problems === [], 'valid game schema: '.json_encode($problems));
    $missingRanking = $game;
    unset($missingRanking['config']['rankings']['level']);
    verify(in_array('drivers.schema_missing_ranking', issueKeys($probe->problems(new Connection($gameSchema), $missingRanking)), true), 'advertised ranking needs a definition');
    $missingHeroField = $game;
    $missingHeroField['config']['features']['heroes']['played_column'] = 'missing_hero_field';
    verify(in_array('drivers.schema_missing_column', issueKeys($probe->problems(new Connection($gameSchema), $missingHeroField)), true), 'all hero query columns must exist');
    $missingLeader = $game;
    $missingLeader['config']['features']['castles']['leader_name_column'] = 'missing_leader_name';
    verify(in_array('drivers.schema_missing_column', issueKeys($probe->problems(new Connection($gameSchema), $missingLeader)), true), 'castle leader fields must exist');
    if (in_array('heroes', $game['config']['capabilities'], true)) {
        $relation = $game['config']['relations'][$game['config']['features']['heroes']['relation']];
        unset($gameSchema->tables[$relation['table']]);
        verify(in_array('drivers.schema_missing_relation', issueKeys($probe->problems(new Connection($gameSchema), $game)), true), 'missing advertised heroes table');
    }

    restore_error_handler();
    echo "OK: $caseCount schema preflight cases (offline, no MySQL)\n";
}
