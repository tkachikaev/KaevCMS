<?php

namespace App\Support\UniversalDrivers;

use App\Models\GameServer;
use App\Models\LoginServer;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persistent, verified copy per connection. This is deliberately NOT wired to
 * legacy production resolvers until the parity/integration phase.
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
     * @param Closure(LoginServer|GameServer,array<string,mixed>):bool $verify
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
            if ($verify($locked, $profile) !== true) {
                throw new ProfileValidationException('Profile has not passed connection/schema verification.');
            }
            $raw = $this->catalog->canonicalJson($profile);
            DB::table('universal_driver_connection_snapshots')->updateOrInsert(
                ['connection_type' => $type, 'connection_id' => $server->getKey()],
                [
                    'profile_id' => $profileId,
                    'format_version' => $profile['format_version'],
                    'profile_sha256' => hash('sha256', $raw),
                    'profile_json' => $raw,
                    'verified_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

    /** @return array<string,mixed>|null Last checked copy, not today's catalog version. */
    public function get(LoginServer|GameServer $server): ?array
    {
        if (! $server->exists) {
            return null;
        }
        $type = $server instanceof LoginServer ? 'login' : 'game';
        $row = DB::table('universal_driver_connection_snapshots')
            ->where('connection_type', $type)->where('connection_id', $server->getKey())->first();
        if ($row === null) {
            return null;
        }
        if (! is_string($row->profile_json) ||
            ! hash_equals($row->profile_sha256, hash('sha256', $row->profile_json))) {
            throw new RuntimeException('Driver connection snapshot integrity check failed.');
        }
        $profile = $this->validator->fromJson($row->profile_json);
        $table = $type === 'login' ? 'login_servers' : 'game_servers';
        $currentDriver = DB::table($table)->where('id', $server->getKey())->value('driver');
        if ($profile['type'] !== $type || $profile['id'] !== $row->profile_id ||
            $profile['format_version'] !== (int) $row->format_version ||
            $currentDriver !== $profile['id']) {
            throw new RuntimeException('Driver connection snapshot no longer matches its connection.');
        }
        return $profile;
    }
}
