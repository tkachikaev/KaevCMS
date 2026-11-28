<?php

use App\Contracts\CharacterRescueGateway;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameAccounts\ExternalGameAccountGateway;
use App\Services\GameWorld\GameWorldDriverResolver;
use App\Services\Servers\MySqlGameServerOnlineCounter;
use App\Services\Servers\ServerConnectionTester;
use App\Services\Servers\UniversalDriverActivation;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\ExternalGameAccountWriteResult;
use App\Support\GameServerFeatures\CharacterRescueWriteResult;
use App\Support\Modules\ModuleManager;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\DriverExtensionAccess;
use App\Support\UniversalDrivers\DriverExtensionCatalog;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\GameProfileEditor;
use App\Support\UniversalDrivers\LoginProfileEditor;
use App\Support\UniversalDrivers\ProfileValidationException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/** Full application/snapshot acceptance; included only by the disposable fixture harness. */
if (getenv('KAEV_UNIVERSAL_MYSQL_TEST') !== '1' || ! isset($pdo, $databaseNames, $created)
    || array_values($databaseNames) !== $created) {
    throw new RuntimeException('Application parity requires databases owned by this fixture invocation.');
}

(static function (PDO $fixture, array $databases, array &$ownedDatabases): void {
    foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array', 'APP_CONFIG_CACHE' => 'bootstrap/cache/fixture-config.php',
        'APP_ROUTES_CACHE' => 'bootstrap/cache/fixture-routes.php', 'APP_EVENTS_CACHE' => 'bootstrap/cache/fixture-events.php'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $application = require __DIR__.'/../../../bootstrap/app.php';
    $kernel = $application->make(Kernel::class);
    $kernel->bootstrap();
    restore_exception_handler();
    if (! $application->environment('testing') || config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('Application fixture refuses a persistent CMS database.');
    }
    if ($kernel->call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Application fixture migrations failed.');
    }
    $assertions = 0;
    $check = static function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };
    $observed = [];
    DB::listen(static function (QueryExecuted $event) use (&$observed): void {
        if (str_starts_with($event->connectionName, 'kaevcms_game_account_')
            || str_starts_with($event->connectionName, 'kaevcms_game_data_')) {
            $observed[$event->connectionName] = $event->connection->getPdo()
                ->query('SELECT @@character_set_connection AS charset, @@collation_connection AS collation')->fetch(PDO::FETCH_ASSOC);
        }
    });
    $catalog = app(DriverProfileCatalog::class);
    $snapshots = app(ConnectionProfileSnapshots::class);
    $activation = app(UniversalDriverActivation::class);
    $gateway = app(ExternalGameAccountGateway::class);
    $reject = static function (LoginServer|GameServer $server) use ($activation, $check): void {
        $query = static fn () => DB::table('universal_driver_connection_snapshots')
            ->where('connection_type', $server instanceof LoginServer ? 'login' : 'game')
            ->where('connection_id', $server->id)->value('profile_sha256');
        $before = $query();
        try {
            $activation->apply($server);
        } catch (ProfileValidationException) {
            $check($query() === $before, 'Rejected activation changed the verified snapshot.');

            return;
        }
        throw new RuntimeException('An incompatible schema passed activation.');
    };
    foreach ($databases as $family => $database) {
        $database .= '_app';
        $fixture->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $ownedDatabases[] = $database;
        $fixture->exec('USE `'.$database.'`');
        $sql = file_get_contents(__DIR__.'/../../Fixtures/UniversalDrivers/'.$family.'.sql');
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
            if (trim($statement) !== '') {
                $fixture->exec($statement);
            }
        }
        $connection = ['database_host' => '127.0.0.1', 'database_port' => (int) (getenv('KAEV_MYSQL_TEST_PORT') ?: 3306),
            'database_name' => $database, 'database_username' => getenv('KAEV_MYSQL_TEST_USER') ?: 'root',
            'database_password' => getenv('KAEV_MYSQL_TEST_PASSWORD') ?: '', 'database_charset' => 'latin1'];
        $fixture->exec('ALTER TABLE accounts ADD UNIQUE KEY fixture_login (login)');
        $builtInLogin = LoginServer::factory()->create(['driver' => $family, ...$connection]);
        $builtInGame = GameServer::factory()->create(['driver' => $family, 'login_server_id' => $builtInLogin->id,
            'use_login_server_connection' => true, 'chronicle' => 'Interlude']);
        $tester = app(ServerConnectionTester::class);
        $check($tester->testLoginServer($builtInLogin)['compatible'] === true
            && $tester->testGameServer($builtInGame)['compatible'] === true, 'Bundled profile diagnostic failed.');
        $previousObserved = $observed;
        $check($gateway->accountSummary($builtInLogin, 'alice')['login'] === 'alice'
            && array_column($gateway->characters($builtInGame, 'alice'), 'name') === ['AliceKnight'], 'Bundled runtime ownership lookup failed.');
        $prepared = $gateway->prepareAccount($builtInLogin, 'BundledCreated', 'Fixture-Password-123', 'bundled@example.invalid');
        $check($gateway->createPreparedAccount($builtInLogin, $prepared) === ExternalGameAccountWriteResult::Created
            && $gateway->inspectPreparedAccount($builtInLogin, $prepared) === ExternalGameAccountState::Matching
            && $gateway->changePassword($builtInLogin, $prepared->login, 'Changed-Fixture-456'), 'Bundled account write/recovery failed.');
        $check(app(GameWorldDriverResolver::class)->resolve($builtInGame)->ranking($builtInGame, 'level', 1)[0]['name'] === 'AliceKnight', 'Bundled runtime ranking failed.');
        $nativeEncoding = array_diff_key($observed, $previousObserved);
        $check($nativeEncoding !== [], 'Bundled runtime did not open an external connection.');
        foreach ($nativeEncoding as $encoding) {
            $check($encoding['charset'] === 'latin1', 'Bundled profile changed the configured connection charset.');
        }
        $observed = $previousObserved;
        $fixture->exec("DELETE FROM accounts WHERE login = 'BundledCreated'");
        $fixture->exec('RENAME TABLE accounts TO mapped_accounts, characters TO mapped_characters');
        $id = $family === 'rusacis' ? 'obj_Id' : 'charId';
        $fixture->exec('ALTER TABLE mapped_characters CHANGE COLUMN `'.$id.'` player_id BIGINT NOT NULL');
        $fixture->exec('ALTER TABLE mapped_characters ADD COLUMN born_on VARCHAR(32) NULL');
        $fixture->exec('ALTER TABLE mapped_characters CHANGE COLUMN online is_connected BIGINT NULL');
        $fixture->exec("UPDATE mapped_characters SET born_on='2026-10-20 15:23:01'");
        $login = $catalog->find('login', $family)['profile'];
        $login['id'] = 'fixture-login-'.$family;
        $login['name'] = 'Fixture login '.$family;
        $login['config']['accounts']['table'] = 'mapped_accounts';
        $login['config']['database'] = ['dialect' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'];
        $login = (new LoginProfileEditor)->prepare($login, 'accounts');
        $catalog->create($login);
        $game = $catalog->find('game', $family)['profile'];
        $original = $game['config'];
        $game['id'] = 'fixture-game-'.$family;
        $game['name'] = 'Fixture game '.$family;
        $game['config']['database'] = $login['config']['database'];
        $game['config']['characters']['table'] = 'mapped_characters';
        $game['config']['characters']['fields']['id']['column'] = 'player_id';
        $game['config']['characters']['fields']['created_at'] = ['kind' => 'column', 'column' => 'born_on'];
        $game['config']['characters']['fields']['online'] = ['kind' => 'column', 'column' => 'is_connected'];
        $game = (new GameProfileEditor)->prepare($game, $original);
        $catalog->create($game);
        $loginServer = LoginServer::factory()->create(['driver' => $login['id'], ...$connection]);
        $gameServer = GameServer::factory()->create(['driver' => $game['id'], 'login_server_id' => $loginServer->id,
            'use_login_server_connection' => false, 'chronicle' => 'Interlude', ...$connection]);
        $fixture->exec('ALTER TABLE mapped_accounts DROP INDEX fixture_login');
        $reject($loginServer);
        $fixture->exec('ALTER TABLE mapped_accounts ADD UNIQUE KEY fixture_login (login)');
        if ($family === 'rusacis') {
            $fixture->exec('ALTER TABLE mapped_accounts MODIFY password VARCHAR(45) NULL');
            $reject($loginServer);
            $fixture->exec('ALTER TABLE mapped_accounts MODIFY password VARCHAR(60) NULL');
        }
        $activation->apply($loginServer);
        try {
            app(MySqlGameServerOnlineCounter::class)->count($gameServer);
            throw new RuntimeException('Unapplied JSON profile counted online players.');
        } catch (RuntimeException $exception) {
            $check(str_contains($exception->getMessage(), 'Apply and verify'), 'Unapplied online profile did not fail before reading the game database.');
        }
        $activation->apply($gameServer);
        $check(app(MySqlGameServerOnlineCounter::class)->count($gameServer) === (int) $fixture->query('SELECT COUNT(*) FROM mapped_characters WHERE is_connected = 1')->fetchColumn(), 'Online count ignored the selected column.');
        $check($snapshots->get($loginServer)['id'] === $login['id'], 'Login activation lost its selected candidate.');
        $check($gateway->accountSummary($loginServer, 'alice')['login'] === 'alice', 'Runtime account lookup failed.');
        $rows = $gateway->characters($gameServer, 'alice');
        $check(count($rows) === 1 && $rows[0]['name'] === 'AliceKnight', 'Custom table/ID ownership/filter mapping failed.');
        $check(($rows[0]['created_at']?->format('Y-m-d')) === '2026-10-20', 'Mapped character creation date was lost by runtime gateway.');
        $check($gateway->characters($gameServer, "alice' OR 1=1 --") === [], 'Runtime lookup escaped the owner constraint.');
        $world = app(GameWorldDriverResolver::class)->resolve($gameServer);
        $check($world->ranking($gameServer, 'level', 1)[0]['name'] === 'AliceKnight', 'Runtime ranking failed with custom ID/table.');
        $check(count($world->heroes($gameServer)) === 1, 'Runtime hero mapping failed.');
        $check(count($world->castleOwners($gameServer)) === 1, 'Runtime castle mapping failed.');

        $rescue = app(CharacterRescueGateway::class);
        $check($rescue->capabilities($gameServer)->supported, 'Verified JSON rescue is unavailable.');
        $check(in_array('character_rescue', $world->capabilities($gameServer), true), 'World capabilities omit verified rescue.');
        $id = (int) $rows[0]['id'];
        $fixture->exec('UPDATE mapped_characters SET lastAccess = 1000000000000, x = 10, y = 20, z = 30 WHERE player_id = '.$id);
        $target = ['x' => 83400, 'y' => 148600, 'z' => -3400];
        $before = CarbonImmutable::now()->subMinutes(5);
        $check($rescue->rescue($gameServer, 'bob', $id, $target, $before)->status === CharacterRescueWriteResult::NOT_FOUND, 'Rescue accepted a foreign account.');
        $fixture->exec('UPDATE mapped_characters SET is_connected = 1 WHERE player_id = '.$id);
        $check($rescue->rescue($gameServer, 'alice', $id, $target, $before)->status === CharacterRescueWriteResult::ONLINE, 'Rescue accepted an online character.');
        $fixture->exec('UPDATE mapped_characters SET is_connected = 0 WHERE player_id = '.$id);
        $write = $rescue->rescue($gameServer, 'alice', $id, $target, $before);
        $check($write->successful() && [$write->oldX, $write->oldY, $write->oldZ] === [10, 20, 30], 'Verified custom JSON coordinate write failed.');
        $actual = $fixture->query('SELECT x, y, z FROM mapped_characters WHERE player_id = '.$id)->fetch(PDO::FETCH_ASSOC);
        $check(is_array($actual) && array_map('intval', $actual) === $target, 'Rescue did not persist the mapped coordinates.');
        $prepared = $gateway->prepareAccount($loginServer, 'RuntimeCreated', 'Fixture-Password-123', 'runtime@example.invalid');
        $check($gateway->createPreparedAccount($loginServer, $prepared) === ExternalGameAccountWriteResult::Created, 'Runtime registration failed.');
        $check($gateway->inspectPreparedAccount($loginServer, $prepared) === ExternalGameAccountState::Matching, 'Runtime registration recovery proof mismatch.');
        $check($gateway->createPreparedAccount($loginServer, $prepared) === ExternalGameAccountWriteResult::AlreadyExists, 'Duplicate runtime registration not detected.');
        $check($gateway->changePassword($loginServer, $prepared->login, 'Changed-Fixture-456'), 'Runtime password update failed.');
        $invalidLogin = $login;
        $invalidLogin['config']['accounts']['create']['fixed_values'] = ['missing_registration_flag' => 0];
        $invalidLogin['config']['requirements'][0]['optional_columns'][] = 'missing_registration_flag';
        $catalog->save($invalidLogin);
        $reject($loginServer);
        $catalog->save($login);
        $fixture->exec('ALTER TABLE mapped_accounts ADD numeric_hash INT NULL');
        $invalidLogin = $login;
        $invalidLogin['config']['accounts']['fields']['password'] = 'numeric_hash';
        unset($invalidLogin['config']['accounts']['create']['columns']['password']);
        $invalidLogin['config']['accounts']['create']['columns']['numeric_hash'] = 'credential';
        $invalidLogin['config']['requirements'][0]['columns'][] = 'numeric_hash';
        $catalog->save($invalidLogin);
        $reject($loginServer);
        $catalog->save($login);
        $fixture->exec('ALTER TABLE mapped_accounts DROP INDEX fixture_login');
        $reject($loginServer);
        $fixture->exec('ALTER TABLE mapped_accounts ADD UNIQUE KEY fixture_login (login)');
        $check($gateway->accountSummary($loginServer, 'alice')['login'] === 'alice', 'Rejected activation broke the existing read snapshot.');

        foreach (['level', 'heroes', 'castles', 'heroes-details', 'castles-leader'] as $feature) {
            $invalidGame = $game;
            if ($feature === 'level') {
                $invalidGame['config']['rankings']['level']['column'] = 'missing_rank';
            } elseif ($feature === 'heroes-details') {
                $invalidGame['config']['features']['heroes']['played_column'] = 'missing_hero_detail';
            } elseif ($feature === 'castles-leader') {
                $invalidGame['config']['features']['castles']['leader_name_column'] = 'missing_leader_name';
            } else {
                $relation = $game['config']['features'][$feature]['relation'];
                $invalidGame['config']['relations'][$relation]['foreign_column'] = 'missing_join_key';
            }
            $catalog->save($invalidGame);
            $report = $activation->apply($gameServer);
            $disabled = match ($feature) {
                'heroes-details' => 'heroes',
                'castles-leader' => 'castles',
                default => $feature,
            };
            $check(! in_array($disabled, $report['capabilities'], true), 'Unavailable feature was advertised: '.$feature);
            $check(in_array($disabled, $report['unavailable_capabilities'], true), 'Unavailable feature was not reported: '.$feature);
            $check(count($gateway->characters($gameServer, 'alice')) === 1, 'Optional feature broke character lookup: '.$feature);
        }
        $catalog->save($game);
        $fixture->exec('RENAME TABLE heroes TO fixture_hidden_heroes, castle TO fixture_hidden_castle');
        try {
            $report = $activation->apply($gameServer);
            $check($report['compatible'] === true && in_array('level', $report['capabilities'], true), 'Missing optional tables blocked activation or level ranking.');
            $check(! in_array('heroes', $report['capabilities'], true) && ! in_array('castles', $report['capabilities'], true), 'Missing optional tables were advertised.');
            $world = app(GameWorldDriverResolver::class)->resolve($gameServer);
            $check($world->ranking($gameServer, 'level', 1)[0]['name'] === 'AliceKnight', 'Missing optional tables broke ranking.');
        } finally {
            $fixture->exec('RENAME TABLE fixture_hidden_heroes TO heroes, fixture_hidden_castle TO castle');
        }
        $activation->apply($gameServer);

        $manifest = ['driver_extensions' => [[
            'key' => 'premium', 'type' => 'game', 'required' => true, 'title' => ['en' => 'Premium'],
            'fields' => [
                ['name' => 'table', 'kind' => 'table', 'required' => true, 'label' => ['en' => 'Table']],
                ['name' => 'until_column', 'kind' => 'column', 'required' => true, 'label' => ['en' => 'Expiry column']],
            ],
        ]]];
        $declarations = DriverExtensionCatalog::fromManifest('fixturemod', 'Fixture module', $manifest);
        app()->instance(DriverExtensionCatalog::class, new class(app(ModuleManager::class), $declarations) extends DriverExtensionCatalog
        {
            public function __construct(ModuleManager $modules, private array $items)
            {
                parent::__construct($modules);
            }

            public function declarations(?string $type = null): array
            {
                return $type === 'login' ? [] : $this->items;
            }
        });
        $fixture->exec('CREATE TABLE fixture_premium (expires_at BIGINT NOT NULL)');
        $extensionGame = $game;
        $extensionGame['id'] = 'fixture-ext-'.$family;
        $extensionGame['config']['extensions'] = ['fixturemod' => ['premium' => ['table' => 'fixture_premium', 'until_column' => 'expires_at']]];
        $catalog->create($extensionGame);
        $extensionServer = GameServer::factory()->create(['driver' => $extensionGame['id'], 'login_server_id' => $loginServer->id,
            'use_login_server_connection' => true]);
        $access = app(DriverExtensionAccess::class);
        $check($access->values($extensionServer, 'fixturemod', 'premium') === [], 'An unapplied custom profile supplied extension values.');
        $activation->apply($extensionServer);
        $check(($access->values($extensionServer, 'fixturemod', 'premium')['until_column'] ?? null) === 'expires_at', 'Verified extension could not read the real inherited schema.');
        $fixture->exec('ALTER TABLE fixture_premium CHANGE COLUMN expires_at renamed_expiry BIGINT NOT NULL');
        $check($access->values($extensionServer, 'fixturemod', 'premium') === [], 'A renamed extension column remained authorized.');

        // A legacy identifier must use its explicitly applied custom snapshot.
        $legacyGame = $game;
        $legacyGame['id'] = $family;
        $catalog->save($legacyGame);
        $legacyServer = GameServer::factory()->create(['driver' => $family, 'login_server_id' => $loginServer->id,
            'use_login_server_connection' => true]);
        $activation->apply($legacyServer);
        $check(app(MySqlGameServerOnlineCounter::class)->count($legacyServer) === (int) $fixture->query('SELECT COUNT(*) FROM mapped_characters WHERE is_connected = 1')->fetchColumn(), 'Legacy identifier overrode the snapshot online mapping.');
        unset($legacyGame['config']['characters']['fields']['online']);
        $catalog->save($legacyGame);
        $activation->apply($legacyServer);
        try {
            app(MySqlGameServerOnlineCounter::class)->count($legacyServer);
            throw new RuntimeException('A snapshot without an online column fell back to legacy SQL.');
        } catch (RuntimeException $exception) {
            $check(str_contains($exception->getMessage(), 'does not provide online counting'), 'Unexpected failure when online mapping is absent.');
        }
        $fixture->exec('CREATE TABLE fixture_online_stats (char_id INT NOT NULL, stat_key VARCHAR(32), stat_value VARCHAR(32))');
        $legacyGame['config']['relations']['online_stats'] = ['table' => 'fixture_online_stats', 'local_column' => 'player_id', 'foreign_column' => 'char_id', 'join' => 'left', 'cardinality' => 'many'];
        $legacyGame['config']['characters']['fields']['online'] = ['kind' => 'eav', 'relation' => 'online_stats', 'key_column' => 'stat_key', 'value_column' => 'stat_value', 'key_value' => 'online'];
        $catalog->save($legacyGame);
        $activation->apply($legacyServer);
        try {
            app(MySqlGameServerOnlineCounter::class)->count($legacyServer);
            throw new RuntimeException('EAV online mapping fell back to legacy SQL.');
        } catch (RuntimeException $exception) {
            $check(str_contains($exception->getMessage(), 'does not provide online counting'), 'Unexpected failure for unsupported EAV online.');
        }
        $gameServer->update(['use_login_server_connection' => true]);
        try {
            $snapshots->get($gameServer);
            throw new RuntimeException('Changed inheritance reused stale approval.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Changed inheritance reused stale approval.') {
                throw $exception;
            }
        }
        $activation->apply($gameServer);
        $check(count($gateway->characters($gameServer, 'alice')) === 1, 'Inherited connection mapping failed.');
        foreach ([$loginServer, $gameServer] as $server) {
            $type = $server instanceof LoginServer ? 'login' : 'game';
            $entry = $catalog->find($type, $server->driver)['profile'];
            $entry['config']['database']['collation'] = 'utf8mb4_russian_ci';
            $catalog->save($entry);
            $oldHash = $snapshots->get($server)['config']['database']['collation'];
            try {
                $activation->apply($server);
                throw new RuntimeException('Unsupported server collation passed activation.');
            } catch (ProfileValidationException) {
                $check($snapshots->get($server)['config']['database']['collation'] === $oldHash, 'Failed verification replaced active snapshot.');
            }
        }
        $check(count($gateway->characters($gameServer, 'alice')) === 1, 'Catalog edit changed running snapshot.');
        echo "OK: $family editor-produced custom schema, snapshot activation, runtime account/character/world reads, writes, inheritance and failed-activation rollback\n";
    }
    $check($observed !== [], 'Runtime connection instrumentation did not execute.');
    foreach ($observed as $encoding) {
        $check($encoding === ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'], 'Runtime ignored verified profile encoding: '.json_encode($encoding));
    }
    echo 'OK: actual MySQL session encodings and application acceptance ('.$assertions." assertions)\n";
})($pdo, $databaseNames, $created);
