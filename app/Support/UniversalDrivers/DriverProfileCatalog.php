<?php

namespace App\Support\UniversalDrivers;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Offline, CMS-owned catalog. Bundled JSON and locally stored JSON use one format.
 * This does not change the legacy driver registry or open a game connection.
 */
final class DriverProfileCatalog
{
    public function __construct(
        private readonly BundledProfileCatalog $bundled = new BundledProfileCatalog,
        private readonly ProfileValidator $validator = new ProfileValidator,
    ) {}

    /** @return array<string,array{profile:array<string,mixed>,origin:string,modified:bool}> */
    public function all(): array
    {
        $profiles = [];
        foreach ($this->bundled->all() as $key => $profile) {
            $profiles[$key] = ['profile' => $profile, 'origin' => 'builtin', 'modified' => false];
        }
        foreach (DB::table('universal_driver_profiles')->orderBy('id')->get() as $row) {
            $key = $row->type.':'.$row->profile_id;
            if ((bool) $row->is_deleted) {
                unset($profiles[$key]);
                continue;
            }
            $profile = $this->decodeStored($row->profile_json, $row->type, $row->profile_id);
            $profiles[$key] = [
                'profile' => $profile,
                'origin' => isset($profiles[$key]) ? 'builtin' : 'custom',
                'modified' => isset($profiles[$key]),
            ];
        }
        ksort($profiles);

        return $profiles;
    }

    /** @return array{profile:array<string,mixed>,origin:string,modified:bool}|null */
    public function find(string $type, string $id): ?array
    {
        $this->identity($type, $id);
        return $this->all()[$type.':'.$id] ?? null;
    }

    /**
     * Create or edit a profile without any game database. Editing bundled entries
     * transparently creates a local override under the same stable identity.
     *
     * @param array<string,mixed> $profile
     */
    public function save(array $profile): void
    {
        $this->validator->validate($profile);
        $type = $profile['type'];
        $id = $profile['id'];
        $this->identity($type, $id);
        $json = $this->canonicalJson($profile);

        DB::transaction(function () use ($type, $id, $json): void {
            $row = DB::table('universal_driver_profiles')
                ->where('type', $type)->where('profile_id', $id)->lockForUpdate()->first();
            if ($row !== null && (bool) $row->is_deleted) {
                throw new ProfileValidationException('Restore the deleted built-in profile before editing it.');
            }
            if ($row === null) {
                DB::table('universal_driver_profiles')->insert([
                    'type' => $type, 'profile_id' => $id, 'profile_json' => $json,
                    'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('universal_driver_profiles')->where('id', $row->id)
                    ->update(['profile_json' => $json, 'updated_at' => now()]);
            }
        });
    }

    /**
     * Imports any valid JSON as a new independent profile. An existing identity is
     * never overwritten, including a locally deleted bundled identity.
     * @return array<string,mixed> Imported profile with its final identity/name.
     */
    public function import(string $json): array
    {
        $profile = $this->validator->fromJson($json);
        $this->identity($profile['type'], $profile['id']);
        for ($suffix = 1; $suffix <= 999; $suffix++) {
            $candidate = $profile;
            if ($suffix > 1) {
                $postfix = '-'.$suffix;
                $candidate['id'] = rtrim(substr($profile['id'], 0, 64 - strlen($postfix)), '._-').$postfix;
                $label = ' ('.$suffix.')';
                $candidate['name'] = mb_substr($profile['name'], 0, 100 - mb_strlen($label)).$label;
            }
            $this->validator->validate($candidate);
            $identity = $candidate['type'].':'.$candidate['id'];
            $existing = $this->all();
            if (isset($this->bundled->all()[$identity]) || isset($existing[$identity])) {
                continue;
            }
            // Avoid duplicate display names even when the imported ID is new.
            $nameAlreadyExists = false;
            foreach ($existing as $entry) {
                if ($entry['profile']['type'] === $candidate['type'] &&
                    mb_strtolower($entry['profile']['name']) === mb_strtolower($candidate['name'])) {
                    $nameAlreadyExists = true;
                    break;
                }
            }
            if ($nameAlreadyExists) {
                continue;
            }
            try {
                DB::table('universal_driver_profiles')->insert([
                    'type' => $candidate['type'], 'profile_id' => $candidate['id'],
                    'profile_json' => $this->canonicalJson($candidate), 'is_deleted' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                return $candidate;
            } catch (QueryException $e) {
                // A collision is expected when importing the same JSON concurrently.
                // Other database failures must not be hidden or reclassified.
                if (! $this->duplicateKey($e)) {
                    throw $e;
                }
            }
        }
        throw new ProfileValidationException('Unable to allocate a unique driver profile ID.');
    }

    public function export(string $type, string $id): string
    {
        $entry = $this->find($type, $id);
        if ($entry === null) {
            throw new ProfileValidationException('Driver profile not found.');
        }
        return $this->canonicalJson($entry['profile'])."\n";
    }

    /** Hides a bundled profile or deletes a custom profile, only if unused. */
    public function delete(string $type, string $id): void
    {
        $this->identity($type, $id);
        DB::transaction(function () use ($type, $id): void {
            $this->assertNotUsed($type, $id);
            $bundled = isset($this->bundled->all()[$type.':'.$id]);
            $record = DB::table('universal_driver_profiles')->where('type', $type)
                ->where('profile_id', $id)->lockForUpdate()->first();
            if ($bundled) {
                $values = ['profile_json' => null, 'is_deleted' => true, 'updated_at' => now()];
                if ($record !== null) {
                    DB::table('universal_driver_profiles')->where('id', $record->id)->update($values);
                } else {
                    DB::table('universal_driver_profiles')->insert($values + [
                        'type' => $type, 'profile_id' => $id, 'created_at' => now(),
                    ]);
                }
            } elseif ($record !== null && ! (bool) $record->is_deleted) {
                DB::table('universal_driver_profiles')->where('id', $record->id)->delete();
            } else {
                throw new ProfileValidationException('Driver profile not found.');
            }
        });
    }

    /** Restore original release configuration, or unhide a deleted bundled profile. */
    public function restoreBuiltin(string $type, string $id): void
    {
        $this->identity($type, $id);
        if (! isset($this->bundled->all()[$type.':'.$id])) {
            throw new ProfileValidationException('No built-in profile exists for this identity.');
        }
        DB::table('universal_driver_profiles')->where('type', $type)->where('profile_id', $id)->delete();
    }

    /** @param array<string,mixed> $profile */
    public function canonicalJson(array $profile): string
    {
        $this->validator->validate($profile);
        $this->identity($profile['type'], $profile['id']);
        $json = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > ProfileFormat::MAX_BYTES) {
            throw new ProfileValidationException('Driver profile is too large.');
        }
        return $json;
    }

    private function identity(string $type, string $id): void
    {
        if (! in_array($type, ['login', 'game'], true) || strlen($id) > 64 ||
            preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $id) !== 1) {
            throw new ProfileValidationException('Invalid driver identity (maximum ID length: 64).');
        }
    }

    /** @return array<string,mixed> */
    private function decodeStored(?string $json, string $type, string $id): array
    {
        if (! is_string($json)) {
            throw new RuntimeException('Stored driver profile is missing.');
        }
        $profile = $this->validator->fromJson($json);
        if ($profile['type'] !== $type || $profile['id'] !== $id) {
            throw new RuntimeException('Stored driver profile identity does not match its database key.');
        }
        return $profile;
    }

    private function assertNotUsed(string $type, string $id): void
    {
        $table = $type === 'login' ? 'login_servers' : 'game_servers';
        if (DB::table($table)->where('driver', $id)->exists() ||
            DB::table('universal_driver_connection_snapshots')
                ->where('connection_type', $type)->where('profile_id', $id)->exists()) {
            throw new ProfileValidationException('Driver is used by a connected server and cannot be deleted.');
        }
    }

    private function duplicateKey(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true) &&
            in_array((int) ($exception->errorInfo[1] ?? 0), [19, 1062, 2067, 1555], true);
    }
}
