<?php

namespace App\Support\UniversalDrivers;

use App\Models\GameServer;
use App\Models\LoginServer;

/** Decides whether the drivers in use give a module everything it declared as required. */
final class DriverExtensionRequirements
{
    public function __construct(private readonly DriverExtensionAccess $access,
        private readonly DriverExtensionSchemaVerifier $schema,
    ) {}

    /**
     * One readable sentence per server whose driver misses something the module needs. An empty
     * list means the module may be switched on.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public function problems(string $moduleId, string $moduleName, array $manifest): array
    {
        $problems = [];
        foreach (DriverExtensionCatalog::fromManifest($moduleId, $moduleName, $manifest) as $declaration) {
            if ($declaration['required'] !== true) {
                continue;
            }
            $servers = $declaration['type'] === 'login' ? LoginServer::query()->get() : GameServer::query()->get();
            $kind = __($declaration['type'] === 'login' ? 'drivers.ext_server_login' : 'drivers.ext_server_game');
            foreach ($servers as $server) {
                $profile = $this->access->profileFor($server);
                if ($profile === null) {
                    $problems[] = __('drivers.ext_gate_no_driver', ['module' => $moduleName, 'driver' => (string) $server->driver, 'kind' => $kind, 'server' => (string) $server->name]);

                    continue;
                }
                $missing = DriverExtensionCatalog::missing($profile, [$declaration]);
                if ($missing !== []) {
                    $problems[] = __('drivers.ext_gate_missing', [
                        'module' => $moduleName,
                        'driver' => (string) $profile['name'],
                        'kind' => $kind,
                        'server' => (string) $server->name,
                        'fields' => implode(', ', array_map(static fn (array $item): string => $item['label'], $missing)),
                    ]);

                    continue;
                }
                if (! $this->schema->compatible($server, $profile, $declaration)) {
                    $problems[] = __('drivers.ext_gate_schema', [
                        'module' => $moduleName, 'kind' => $kind, 'server' => (string) $server->name,
                    ]);
                }
            }
        }

        return $problems;
    }
}
