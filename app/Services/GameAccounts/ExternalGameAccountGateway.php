<?php

namespace App\Services\GameAccounts;

use App\Contracts\GameAccountGateway;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\Servers\MySqlSessionQueryTimeout;
use App\Services\Servers\ServerDriverRegistry;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

final class ExternalGameAccountGateway implements GameAccountGateway
{
    public function __construct(
        private readonly MobiusPasswordEncoder $passwordEncoder,
        private readonly MySqlSessionQueryTimeout $queryTimeout,
        private readonly ServerDriverRegistry $drivers,
        private readonly MobiusGameSchemaInspector $schemas,
    ) {}

    public function supportsLoginServer(LoginServer $loginServer): bool
    {
        return in_array($loginServer->driver, ['l2j_mobius', 'l2j_mobius_legacy'], true);
    }

    public function supportsGameServer(GameServer $gameServer): bool
    {
        return $gameServer->driver === 'l2j_mobius_ct0_interlude'
            && $gameServer->loginServer instanceof LoginServer
            && $this->supportsLoginServer($gameServer->loginServer);
    }

    public function accountExists(LoginServer $loginServer, string $login): bool
    {
        return $this->withLoginConnection(
            $loginServer,
            static fn (Connection $database): bool => $database->table('accounts')
                ->where('login', $login)
                ->exists(),
        );
    }

    public function createAccount(LoginServer $loginServer, string $login, string $password, string $email): void
    {
        $this->withLoginConnection($loginServer, function (Connection $database) use ($login, $password, $email): void {
            $database->table('accounts')->insert([
                'login' => $login,
                'password' => $this->passwordEncoder->encode($password),
                'email' => Str::lower(trim($email)),
            ]);
        });
    }

    public function changePassword(LoginServer $loginServer, string $login, string $password): bool
    {
        return $this->withLoginConnection(
            $loginServer,
            fn (Connection $database): bool => $database->table('accounts')
                ->where('login', $login)
                ->update(['password' => $this->passwordEncoder->encode($password)]) === 1,
        );
    }

    public function accountSummary(LoginServer $loginServer, string $login): ?array
    {
        return $this->withLoginConnection($loginServer, static function (Connection $database) use ($login): ?array {
            $account = $database->table('accounts')
                ->where('login', $login)
                ->first(['login', 'created_time', 'lastactive', 'accessLevel']);

            if ($account === null) {
                return null;
            }

            return [
                'login' => (string) $account->login,
                'created_at' => is_scalar($account->created_time) ? (string) $account->created_time : null,
                'last_active' => is_numeric($account->lastactive) ? (int) $account->lastactive : 0,
                'status' => is_numeric($account->accessLevel) && (int) $account->accessLevel < 0
                    ? 'blocked'
                    : 'active',
            ];
        });
    }

    public function characters(GameServer $gameServer, string $login): array
    {
        $limit = $this->characterLimit();
        $createdAtColumn = $this->characterCreatedAtColumn($gameServer);

        return $this->withGameConnection(
            $gameServer,
            function (Connection $database) use ($gameServer, $login, $limit, $createdAtColumn): array {
                $schema = $database->getSchemaBuilder();
                $profile = $this->schemas->inspect($database, (string) $gameServer->chronicle);
                $query = $database->table('characters')
                    ->where('characters.account_name', $login)
                    ->where('characters.deletetime', 0)
                    ->where('characters.accesslevel', 0)
                    ->orderByDesc('characters.level')
                    ->orderBy('characters.char_name');

                if ($schema->hasTable('clan_data')) {
                    $query->leftJoin('clan_data', 'clan_data.clan_id', '=', 'characters.clanid')
                        ->addSelect('clan_data.clan_name as clan_name');
                } else {
                    $query->selectRaw('NULL as clan_name');
                }

                if ($profile->heroesAvailable) {
                    $query->leftJoin('heroes', 'heroes.charId', '=', 'characters.charId')
                        ->selectRaw('CASE WHEN heroes.charId IS NULL THEN 0 ELSE 1 END as hero');
                } else {
                    $query->selectRaw('0 as hero');
                }

                if ($createdAtColumn !== null && $schema->hasColumn('characters', $createdAtColumn)) {
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
