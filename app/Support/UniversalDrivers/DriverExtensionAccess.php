<?php

namespace App\Support\UniversalDrivers;

use App\Models\GameServer;
use App\Models\LoginServer;
use Throwable;

/**
 * What a module calls at run time to learn where its data lives in the game or login database of
 * one connected server: the answers the administrator gave in the driver's "Modules" section.
 *
 * The verified connection snapshot wins; connections without an applied snapshot may use only their bundled, release-owned profile.
 */
final class DriverExtensionAccess
{
    public function __construct(
        private readonly ConnectionProfileSnapshots $snapshots,
        private readonly BundledProfileCatalog $bundled,
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

            // The immutable shipped profile remains active until an explicit
            // application, regardless of edits to the catalog entry.
            return $this->bundled->all()[$type.':'.$server->driver] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed> field => value; empty when the driver does not answer this extension
     */
    public function values(LoginServer|GameServer $server, string $moduleId, string $key): array
    {
        // Re-check at the moment of use. A schema can change after a module
        // was enabled, so persisted answers are not permanent authorization.
        $profile = $this->profileFor($server);
        if ($profile === null) {
            return [];
        }
        $values = $profile['config']['extensions'][$moduleId][$key] ?? [];
        if (! is_array($values) || $values === []) {
            return [];
        }
        foreach (app(DriverExtensionCatalog::class)->declarations($server instanceof LoginServer ? 'login' : 'game') as $declaration) {
            if ($declaration['module'] === $moduleId && $declaration['key'] === $key) {
                return app(DriverExtensionSchemaVerifier::class)->compatible($server, $profile, $declaration)
                    ? $values : [];
            }
        }

        return []; // Unknown/uninstalled module declarations never get credentials or mappings.
    }
}
