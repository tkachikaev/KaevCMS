<?php

namespace App\Support\UniversalDrivers;

use App\Exceptions\ExternalDatabaseDriverUnavailable;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Support\Database\TableExistence;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persistent, verified copy per connection. Invalid verification fails closed.
 */
final class ConnectionProfileSnapshots
{
    public function __construct(
        private readonly DriverProfileCatalog $catalog = new DriverProfileCatalog,
        private readonly ProfileValidator $validator = new ProfileValidator,
    ) {}

    /**
     * Caller must provide a real schema/connection verifier for the target game DB.
     * The snapshot is updated only after that verifier succeeds. A check returning
     * false or throwing preserves the last verified snapshot unchanged.
     *
     * @param  Closure(LoginServer|GameServer,array<string,mixed>):bool  $verify
     */
    public function verifyAndRecord(LoginServer|GameServer $server, string $profileId, Closure $verify): void
    {
        $type = $server instanceof LoginServer ? 'login' : 'game';
        if (! $server->exists) {
            throw new ProfileValidationException('Cannot verify an unsaved connection.');
        }
        DB::transaction(function () use ($server, $type, $profileId, $verify): void {
            $locked = $type === 'login'
                ? LoginServer::query()->whereKey($server->getKey())->lockForUpdate()->first()
                : GameServer::query()->whereKey($server->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->driver !== $profileId) {
                throw new ProfileValidationException('The selected server driver changed or does not match the profile.');
            }
            $entry = $this->catalog->find($type, $profileId);
            if ($entry === null) {
                throw new ProfileValidationException('Cannot verify a missing or deleted profile.');
            }
            $profile = $entry['profile'];
            $this->validator->validate($profile);
            // Capture the same inherited target used by the verifier. Do not
            // sign a newly loaded connection after checking a different one.
            $fingerprint = $this->fingerprint($locked);
            if ($verify($locked, $profile) !== true) {
                throw new ProfileValidationException('Profile has not passed connection/schema verification.');
            }
            if ($fingerprint !== $this->fingerprint($locked->fresh() ?? throw new ProfileValidationException('Connection no longer exists.'))) {
                throw new ProfileValidationException('Connection changed during profile verification.');
            }
            $raw = $this->catalog->canonicalJson($profile);
            DB::table('universal_driver_connection_snapshots')->updateOrInsert(
                ['connection_type' => $type, 'connection_id' => $server->getKey()],
                [
                    'profile_id' => $profileId,
                    'format_version' => $profile['format_version'],
                    'profile_sha256' => hash('sha256', $raw),
                    'connection_fingerprint' => $fingerprint,
                    'profile_json' => $raw,
                    'verified_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

    /**
     * Bind the checked profile to the actual DB target/credentials. The secret
     * itself is never persisted: only keyed HMAC. A changed connection cannot
     * silently reuse a stale approval, including an inherited LoginServer DB.
     */
    private function fingerprint(LoginServer|GameServer $server): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Missing application key for driver verification.');
        }
        $target = $server;
        if ($server instanceof GameServer && $server->use_login_server_connection) {
            $server->loadMissing('loginServer');
            $target = $server->loginServer;
            if (! $target instanceof LoginServer || $target->getKey() !== $server->login_server_id) {
                throw new ExternalDatabaseDriverUnavailable('Missing inherited database connection.');
            }
        }
        $values = [
            'type' => $server instanceof LoginServer ? 'login' : 'game',
            'connection_id' => $server->getKey(),
            'driver' => $server->driver,
            'game_login_server_id' => $server instanceof GameServer ? $server->login_server_id : null,
            'game_inherits_db' => $server instanceof GameServer ? $server->use_login_server_connection : null,
            'chronicle' => $server instanceof GameServer ? $server->chronicle : null,
            'db_host' => $target->database_host,
            'db_port' => $target->database_port,
            'db_name' => $target->database_name,
            'db_username' => $target->database_username,
            'db_password' => $target->databasePassword(),
            'db_charset' => $target->database_charset,
        ];

        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR), $key);
    }

    /** @return array<string,mixed>|null Last checked copy, not today's catalog version. */
    public function get(LoginServer|GameServer $server): ?array
    {
        if (! $server->exists || ! app(TableExistence::class)->has('universal_driver_connection_snapshots')) {
            return null;
        }
        $type = $server instanceof LoginServer ? 'login' : 'game';
        $row = DB::table('universal_driver_connection_snapshots')
            ->where('connection_type', $type)->where('connection_id', $server->getKey())->first();
        if ($row === null) {
            $this->assertCurrentTarget($server);

            return null;
        }
        // Legacy experiments could create a snapshot before the connection
        // fingerprint migration. Such a snapshot was never production-active.
        if ($row->connection_fingerprint === null && in_array((string) $server->driver,
            $type === 'login' ? ['l2j_mobius', 'l2j_mobius_legacy', 'rusacis'] : ['l2j_mobius', 'rusacis'], true)) {
            $this->assertCurrentTarget($server);

            return null;
        }
        if (! is_string($row->profile_json) || ! is_string($row->profile_sha256) ||
            ! hash_equals($row->profile_sha256, hash('sha256', $row->profile_json)) ||
            ! is_string($row->connection_fingerprint) ||
            ! hash_equals($row->connection_fingerprint, $this->fingerprint($server))) {
            throw new ExternalDatabaseDriverUnavailable('Driver connection snapshot integrity check failed.');
        }
        try {
            $profile = $this->validator->fromJson($row->profile_json);
        } catch (ProfileValidationException $exception) {
            throw new ExternalDatabaseDriverUnavailable('Driver connection snapshot is invalid.', previous: $exception);
        }
        $current = $server->fresh();
        if ($profile['type'] !== $type || $profile['id'] !== $row->profile_id ||
            $profile['format_version'] !== (int) $row->format_version ||
            $current === null || $current->driver !== $profile['id'] ||
            ! hash_equals($row->connection_fingerprint, $this->fingerprint($current))) {
            throw new ExternalDatabaseDriverUnavailable('Driver connection snapshot no longer matches its connection.');
        }

        return $profile;
    }

    /**
     * @param array<string,mixed>|null $expected
     * @param array<string,mixed>|null $actual
     */
    public static function assertUnchanged(?array $expected, ?array $actual): void
    {
        if ($expected !== $actual) {
            throw new ExternalDatabaseDriverUnavailable('Driver profile changed during the operation.');
        }
    }

    private function assertCurrentTarget(LoginServer|GameServer $server): void
    {
        $current = $server->fresh();
        if ($current === null || ! hash_equals($this->fingerprint($server), $this->fingerprint($current))) {
            throw new ExternalDatabaseDriverUnavailable('Driver connection target changed.');
        }
    }
}
