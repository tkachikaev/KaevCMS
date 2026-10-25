<?php

namespace App\Support\UniversalDrivers;

use App\Models\GameServer;
use App\Models\LoginServer;
use Throwable;

/**
 * What a module calls at run time to learn where its data lives in the game or login database of
 * one connected server: the answers the administrator gave in the driver's "Modules" section.
 *
 * The verified connection snapshot wins; connections still on a built-in PHP driver (or not applied
 * yet) fall back to the driver profile of the same name in the catalogue.
 */
final class DriverExtensionAccess
{
    public function __construct(
        private readonly ConnectionProfileSnapshots $snapshots,
        private readonly DriverProfileCatalog $catalog,
    ) {}

    /** @return array<string, mixed>|null The driver profile in effect for the server, or null when it is unavailable. */
    public function profileFor(LoginServer|GameServer $server): ?array
    {
        $type = $server instanceof LoginServer ? 'login' : 'game';
        try {
            $snapshot = $this->snapshots->get($server);
            if ($snapshot !== null) {
                return $snapshot;
            }

            return $this->catalog->find($type, (string) $server->driver)['profile'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed> field => value; empty when the driver does not answer this extension
     */
    public function values(LoginServer|GameServer $server, string $moduleId, string $key): array
    {
        $values = $this->profileFor($server)['config']['extensions'][$moduleId][$key] ?? [];

        return is_array($values) ? $values : [];
    }
}
