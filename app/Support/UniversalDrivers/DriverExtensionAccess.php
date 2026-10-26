<?php

namespace App\Support\UniversalDrivers;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\Servers\ServerDriverRegistry;
use Throwable;

/**
 * What a module calls at run time to learn where its data lives in the game or login database of
 * one connected server: the answers the administrator gave in the driver's "Modules" section.
 *
 * The verified connection snapshot wins; connections still on a built-in PHP driver (or not applied
 * yet) may fall back only to their bundled, release-owned profile.
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

            // Only a bundled legacy PHP driver may use its shipped JSON as a
            // declaration template. Custom profiles must be applied and verified
            // against THIS server first, not merely saved in the catalogue.
            $legacy = $type === 'login'
                ? [ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::MOBIUS_LEGACY_LOGIN_DRIVER, ServerDriverRegistry::RUSACIS_DRIVER]
                : [ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::RUSACIS_DRIVER];
            if (! in_array((string) $server->driver, $legacy, true)) {
                return null;
            }

            $entry = $this->catalog->find($type, (string) $server->driver);

            return $entry !== null && ! $entry['modified'] ? $entry['profile'] : null;
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
