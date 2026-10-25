<?php

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameAccounts\ExternalGameAccountGateway;
use App\Services\GameWorld\GameWorldDriverResolver;
use App\Services\Servers\UniversalDriverActivation;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\ExternalGameAccountWriteResult;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\GameProfileEditor;
use App\Support\UniversalDrivers\LoginProfileEditor;
use App\Support\UniversalDrivers\ProfileValidationException;
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
        $fixture->exec('RENAME TABLE accounts TO mapped_accounts, characters TO mapped_characters');
        $fixture->exec('ALTER TABLE mapped_accounts ADD UNIQUE KEY fixture_login (login)');
        $id = $family === 'rusacis' ? 'obj_Id' : 'charId';
        $fixture->exec('ALTER TABLE mapped_characters RENAME COLUMN `'.$id.'` TO player_id');
        $fixture->exec('ALTER TABLE mapped_characters ADD COLUMN born_on VARCHAR(32) NULL');
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
        $game = (new GameProfileEditor)->prepare($game, $original);
        $catalog->create($game);
        $connection = ['database_host' => '127.0.0.1', 'database_port' => (int) (getenv('KAEV_MYSQL_TEST_PORT') ?: 3306),
            'database_name' => $database, 'database_username' => getenv('KAEV_MYSQL_TEST_USER') ?: 'root',
            'database_password' => getenv('KAEV_MYSQL_TEST_PASSWORD') ?: '', 'database_charset' => 'latin1'];
        $loginServer = LoginServer::factory()->create(['driver' => $login['id'], ...$connection]);
        $gameServer = GameServer::factory()->create(['driver' => $game['id'], 'login_server_id' => $loginServer->id,
            'use_login_server_connection' => false, 'chronicle' => 'Interlude', ...$connection]);
        $activation->apply($loginServer);
        $activation->apply($gameServer);
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
        $prepared = $gateway->prepareAccount($loginServer, 'RuntimeCreated', 'Fixture-Password-123', 'runtime@example.invalid');
        $check($gateway->createPreparedAccount($loginServer, $prepared) === ExternalGameAccountWriteResult::Created, 'Runtime registration failed.');
        $check($gateway->inspectPreparedAccount($loginServer, $prepared) === ExternalGameAccountState::Matching, 'Runtime registration recovery proof mismatch.');
        $check($gateway->createPreparedAccount($loginServer, $prepared) === ExternalGameAccountWriteResult::AlreadyExists, 'Duplicate runtime registration not detected.');
        $check($gateway->changePassword($loginServer, $prepared->login, 'Changed-Fixture-456'), 'Runtime password update failed.');
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
