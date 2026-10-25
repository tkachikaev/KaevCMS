<?php

namespace App\Services\GameAccounts;

use App\Contracts\GameAccountGateway;
use App\Contracts\LoginServerDriver;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameWorld\GameWorldDriverResolver;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\GameWorld\MobiusGameSchemaProfile;
use App\Services\Servers\MySqlSessionQueryTimeout;
use App\Services\Servers\ServerDriverRegistry;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\ExternalGameAccountWriteResult;
use App\Support\GameAccounts\PreparedGameAccount;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

final class ExternalGameAccountGateway implements GameAccountGateway
{
    public function __construct(
        private readonly LoginServerDriverResolver $loginDrivers,
        private readonly MySqlSessionQueryTimeout $queryTimeout,
        private readonly ServerDriverRegistry $drivers,
        private readonly MobiusGameSchemaInspector $schemas,
        private readonly GameWorldDriverResolver $worldDrivers,
    ) {}

    public function supportsLoginServer(LoginServer $loginServer): bool
    {
        return $this->loginDrivers->supports($loginServer);
    }

    public function supportsGameServer(GameServer $gameServer): bool
    {
        $login = $gameServer->loginServer;
        if (! $login instanceof LoginServer || ! $this->supportsLoginServer($login)) {
            return false;
        }
        try {
            $gameProfile = app(ConnectionProfileSnapshots::class)->get($gameServer);
            $loginProfile = app(ConnectionProfileSnapshots::class)->get($login);
        } catch (RuntimeException) {
            // Connection configuration or snapshot integrity changed: do not
            // advertise unsafe game-account operations to the player.
            return false;
        }
        if ($gameProfile !== null) {
            // Current CMS account API is login-based. ID ownership requires a
            // separately verified account-ID mapping and must fail closed.
            $gameIdentity = $gameProfile['config']['characters']['owner']['identity'] ?? null;
            $loginIdentity = $loginProfile['config']['accounts']['ownership']['identity'] ?? 'login';
            return $gameIdentity === 'login' && $loginIdentity === 'login'
                && in_array('characters', $gameProfile['config']['capabilities'], true);
        }
        if ($loginProfile !== null) {
            // Legacy world readers are login-based, but never assume that all
            // profiles share the same account-identity contract.
            return ($loginProfile['config']['accounts']['ownership']['identity'] ?? null) === 'login'
                && in_array($gameServer->driver, [ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::RUSACIS_DRIVER], true);
        }
        return $this->loginDrivers->resolve($login)->supportsGameDriver((string) $gameServer->driver);
    }

    public function prepareAccount(LoginServer $loginServer, string $login, string $password, string $email): PreparedGameAccount
    {
        return new PreparedGameAccount(
            login: trim($login),
            credential: $this->loginDrivers->resolve($loginServer)->encodePassword($password),
            email: Str::lower(trim($email)),
        );
    }

    public function inspectPreparedAccount(LoginServer $loginServer, PreparedGameAccount $account): ExternalGameAccountState
    {
        $driver = $this->preparedAccountDriver($loginServer, $account);

        return $this->withLoginConnection($loginServer, fn (Connection $database): ExternalGameAccountState => $driver->inspect($database, $account));
    }

    public function createPreparedAccount(LoginServer $loginServer, PreparedGameAccount $account): ExternalGameAccountWriteResult
    {
        $driver = $this->preparedAccountDriver($loginServer, $account);

        return $this->withLoginConnection($loginServer, function (Connection $database) use ($driver, $account): ExternalGameAccountWriteResult {
            try {
                $driver->create($database, $account);
            } catch (QueryException $exception) {
                if ($this->isDuplicateKey($exception)) {
                    return ExternalGameAccountWriteResult::AlreadyExists;
                }
                throw $exception;
            }

            return ExternalGameAccountWriteResult::Created;
        });
    }

    public function changePassword(LoginServer $loginServer, string $login, string $password): bool
    {
        $driver = $this->loginDrivers->resolve($loginServer);

        return $this->withLoginConnection($loginServer, fn (Connection $database): bool => $driver->changePassword($database, $login, $password));
    }

    public function accountSummary(LoginServer $loginServer, string $login): ?array
    {
        $driver = $this->loginDrivers->resolve($loginServer);

        return $this->withLoginConnection($loginServer, fn (Connection $database): ?array => $driver->summary($database, $login));
    }

    private function preparedAccountDriver(LoginServer $loginServer, PreparedGameAccount $account): LoginServerDriver
    {
        $driver = $this->loginDrivers->resolve($loginServer);
        if (! $driver->acceptsCredential($account->credential)) {
            throw new RuntimeException('The prepared credential does not match the selected LoginServer driver.');
        }

        return $driver;
    }

    public function characters(GameServer $gameServer, string $login): array
    {
        if ($gameServer->driver === ServerDriverRegistry::RUSACIS_DRIVER ||
            app(ConnectionProfileSnapshots::class)->get($gameServer) !== null) {
            if (! $this->supportsGameServer($gameServer)) {
                throw new RuntimeException('The selected GameServer driver does not support characters.');
            }

            $rows = $this->worldDrivers->resolve($gameServer)->charactersForAccount($gameServer, $login, $this->characterLimit());

            return array_map(static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'level' => (int) ($row['level'] ?? 0),
                'class_id' => (int) ($row['class_id'] ?? 0),
                'race' => (int) ($row['race'] ?? 0),
                'gender' => (int) ($row['gender'] ?? 0),
                'title' => trim((string) ($row['title'] ?? '')) ?: null,
                'online' => (int) ($row['online'] ?? 0) === 1,
                'clan' => trim((string) ($row['clan_name'] ?? '')) ?: null,
                'last_access' => max(0, (int) ($row['last_seen_at_ms'] ?? 0)),
                'play_time_seconds' => max(0, (int) ($row['play_time_seconds'] ?? 0)),
                'pvp_kills' => max(0, (int) ($row['pvp_kills'] ?? 0)),
                'pk_kills' => max(0, (int) ($row['pk_kills'] ?? 0)),
                'reputation' => (int) ($row['reputation'] ?? 0),
                'noble' => (int) ($row['noble'] ?? 0) === 1,
                'hero' => (int) ($row['hero'] ?? 0) === 1,
                'created_at' => null,
            ], $rows);
        }

        if (! $this->supportsGameServer($gameServer) || $gameServer->driver !== ServerDriverRegistry::MOBIUS_DRIVER) {
            throw new RuntimeException('Unverified GameServer driver cannot read characters.');
        }
        $limit = $this->characterLimit();
        $createdAtColumn = $this->characterCreatedAtColumn($gameServer);

        return $this->withGameConnection(
            $gameServer,
            function (Connection $database) use ($gameServer, $login, $limit, $createdAtColumn): array {
                $profile = $this->schemas->remembered(
                    $gameServer,
                    fn (): MobiusGameSchemaProfile => $this->schemas->inspect($database, (string) $gameServer->chronicle),
                );
                $query = $database->table('characters')
                    ->where('characters.account_name', $login)
                    ->where('characters.deletetime', 0)
                    ->where('characters.accesslevel', 0)
                    ->orderByDesc('characters.level')
                    ->orderBy('characters.char_name');

                $query->leftJoin('clan_data', 'clan_data.clan_id', '=', 'characters.clanid')
                    ->addSelect('clan_data.clan_name as clan_name');

                if ($profile->heroesAvailable) {
                    $query->leftJoin('heroes', 'heroes.charId', '=', 'characters.charId')
                        ->selectRaw('CASE WHEN heroes.charId IS NULL THEN 0 ELSE 1 END as hero');
                } else {
                    $query->selectRaw('0 as hero');
                }

                if ($createdAtColumn !== null && $this->hasCharacterColumn($database, $gameServer, $createdAtColumn)) {
                    $query->addSelect('characters.'.$createdAtColumn.' as character_created_at');
                } else {
                    $query->selectRaw('NULL as character_created_at');
                }

                $rows = $query->addSelect([
                    'characters.charId',
                    'characters.char_name',
                    'characters.level',
                    'characters.classid',
                    'characters.race',
                    'characters.sex',
                    'characters.title',
                    'characters.online',
                    'characters.lastAccess',
                    'characters.onlinetime',
                    'characters.pvpkills',
                    'characters.pkkills',
                    'characters.'.$profile->reputationColumn.' as reputation',
                    'characters.nobless',
                ])->limit($limit)->get();

                return $rows->map(fn (object $character): array => [
                    'id' => (int) $character->charId,
                    'name' => (string) $character->char_name,
                    'level' => (int) $character->level,
                    'class_id' => (int) $character->classid,
                    'race' => (int) $character->race,
                    'gender' => (int) $character->sex,
                    'title' => trim((string) $character->title) !== '' ? (string) $character->title : null,
                    'online' => (int) $character->online === 1,
                    'clan' => isset($character->clan_name) && trim((string) $character->clan_name) !== ''
                        ? (string) $character->clan_name
                        : null,
                    'last_access' => is_numeric($character->lastAccess) ? (int) $character->lastAccess : 0,
                    'play_time_seconds' => is_numeric($character->onlinetime) ? max(0, (int) $character->onlinetime) : 0,
                    'pvp_kills' => is_numeric($character->pvpkills) ? max(0, (int) $character->pvpkills) : 0,
                    'pk_kills' => is_numeric($character->pkkills) ? max(0, (int) $character->pkkills) : 0,
                    'reputation' => is_numeric($character->reputation) ? (int) $character->reputation : 0,
                    'noble' => (int) $character->nobless === 1,
                    'hero' => (int) $character->hero === 1,
                    'created_at' => $this->parseCharacterCreatedAt($character->character_created_at ?? null),
                ])->all();
            },
        );
    }

    /**
     * @template T
     *
     * @param  Closure(Connection): T  $callback
     * @return T
     */
    private function withLoginConnection(LoginServer $loginServer, Closure $callback): mixed
    {
        if (! $this->supportsLoginServer($loginServer)) {
            throw new RuntimeException('The selected LoginServer driver does not support game accounts.');
        }

        return $this->withConnection([
            'host' => $loginServer->database_host,
            'port' => $loginServer->database_port,
            'database' => $loginServer->database_name,
            'username' => $loginServer->database_username,
            'password' => $loginServer->databasePassword() ?? '',
            'charset' => $loginServer->database_charset,
        ], $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(Connection): T  $callback
     * @return T
     */
    private function withGameConnection(GameServer $gameServer, Closure $callback): mixed
    {
        $gameServer->loadMissing('loginServer');
        if (! $this->supportsGameServer($gameServer)) {
            throw new RuntimeException('The selected GameServer driver does not support characters.');
        }

        $loginServer = $gameServer->loginServer;
        if (! $loginServer instanceof LoginServer) {
            throw new RuntimeException('The selected GameServer has no LoginServer connection.');
        }

        $connection = $gameServer->use_login_server_connection
            ? [
                'host' => $loginServer->database_host,
                'port' => $loginServer->database_port,
                'database' => $loginServer->database_name,
                'username' => $loginServer->database_username,
                'password' => $loginServer->databasePassword() ?? '',
                'charset' => $loginServer->database_charset,
            ]
            : [
                'host' => (string) $gameServer->database_host,
                'port' => (int) $gameServer->database_port,
                'database' => (string) $gameServer->database_name,
                'username' => (string) $gameServer->database_username,
                'password' => $gameServer->databasePassword() ?? '',
                'charset' => (string) $gameServer->database_charset,
            ];

        return $this->withConnection($connection, $callback);
    }

    /**
     * @template T
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string, charset: string}  $values
     * @param  Closure(Connection): T  $callback
     * @return T
     */
    private function withConnection(array $values, Closure $callback): mixed
    {
        $name = 'kaevcms_game_account_'.Str::lower(Str::random(12));
        $configuration = [
            'driver' => 'mysql',
            'host' => $values['host'],
            'port' => $values['port'],
            'database' => $values['database'],
            'username' => $values['username'],
            'password' => $values['password'],
            'charset' => $values['charset'],
            'collation' => $this->collationFor($values['charset']),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [PDO::ATTR_TIMEOUT => $this->connectTimeoutSeconds()],
        ];

        try {
            $database = DB::connectUsing($name, $configuration, true);
            if (! $database instanceof Connection) {
                throw new RuntimeException('Unsupported external database connection type.');
            }

            $this->queryTimeout->apply($database);

            return $callback($database);
        } finally {
            try {
                DB::purge($name);
            } catch (Throwable) {
                // A cleanup failure must not replace the database operation result.
            }
        }
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return (string) $exception->getCode() === '23000' && (int) $driverCode === 1062;
    }

    /** Remembered like the schema profile: checking reads information_schema on the game database. */
    private function hasCharacterColumn(Connection $database, GameServer $gameServer, string $column): bool
    {
        if (! $gameServer->exists) {
            return $database->getSchemaBuilder()->hasColumn('characters', $column);
        }

        return (bool) Cache::remember(
            implode(':', ['mobius-character-column-v1', $gameServer->id, $gameServer->updated_at?->getTimestamp() ?? 0, $column]),
            now()->addMinutes(5),
            static fn (): bool => $database->getSchemaBuilder()->hasColumn('characters', $column),
        );
    }

    private function characterCreatedAtColumn(GameServer $gameServer): ?string
    {
        $driver = $this->drivers->gameDriver((string) $gameServer->driver);
        $column = $driver['character_created_at_column'] ?? null;

        if (! is_string($column) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $column) !== 1) {
            return null;
        }

        return $column;
    }

    private function parseCharacterCreatedAt(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (! is_scalar($value)) {
            return null;
        }

        $date = trim((string) $value);
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, min(30, (int) config('cms.external_database.connect_timeout_seconds', 3)));
    }

    private function characterLimit(): int
    {
        return max(1, min(100, (int) config('cms.external_database.character_limit', 50)));
    }

    private function collationFor(string $charset): string
    {
        return match ($charset) {
            'utf8' => 'utf8_unicode_ci',
            'latin1' => 'latin1_swedish_ci',
            'cp1251' => 'cp1251_general_ci',
            default => 'utf8mb4_unicode_ci',
        };
    }
}
