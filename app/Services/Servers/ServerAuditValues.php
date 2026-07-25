<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use App\Models\LoginServer;

final class ServerAuditValues
{
    /** @var array<string, string> */
    private const CONNECTION_CHANGE_LABELS = [
        'database_host' => 'database_address_changed',
        'database_port' => 'database_port_changed',
        'database_name' => 'database_schema_changed',
        'database_username' => 'database_account_changed',
        'database_password' => 'database_authentication_changed',
        'database_charset' => 'database_charset_changed',
        'service_host' => 'service_address_changed',
        'service_port' => 'service_port_changed',
    ];

    /** @return array<string, mixed> */
    public function loginServer(LoginServer $server): array
    {
        return [
            'name' => $server->name,
            'driver' => $server->driver,
            'database_connection_configured' => $this->loginDatabaseConfigured($server),
            'service_address_configured' => $this->serviceConfigured($server),
            'database_password_saved' => $server->hasDatabasePassword(),
        ];
    }

    /** @return array<string, mixed> */
    public function gameServer(GameServer $server): array
    {
        return [
            'name' => $server->name,
            'rates' => $server->rates,
            'chronicle' => $server->chronicle,
            'mode' => $server->mode,
            'maintenance_enabled' => (bool) $server->maintenance_enabled,
            'statistics_enabled' => (bool) $server->statistics_enabled,
            'statistics_sections' => [
                'level' => (bool) $server->statistics_level_enabled,
                'pvp' => (bool) $server->statistics_pvp_enabled,
                'pk' => (bool) $server->statistics_pk_enabled,
                'play_time' => (bool) $server->statistics_play_time_enabled,
                'heroes' => (bool) $server->statistics_heroes_enabled,
                'castles' => (bool) $server->statistics_castles_enabled,
            ],
            'statistics_limits' => [
                'level' => (int) $server->statistics_level_limit,
                'pvp' => (int) $server->statistics_pvp_limit,
                'pk' => (int) $server->statistics_pk_limit,
                'play_time' => (int) $server->statistics_play_time_limit,
            ],
            'login_server_id' => $server->login_server_id,
            'driver' => $server->driver,
            'use_login_server_connection' => (bool) $server->use_login_server_connection,
            'database_connection_configured' => $server->connectionConfigured(),
            'service_address_configured' => $this->serviceConfigured($server),
            'database_password_saved' => $server->hasDatabasePassword(),
        ];
    }

    /** @return array<string, string> */
    public function connectionFingerprints(LoginServer|GameServer $server): array
    {
        $values = [
            'database_host' => $server->database_host,
            'database_port' => $server->database_port,
            'database_name' => $server->database_name,
            'database_username' => $server->database_username,
            'database_password' => $server->databasePassword(),
            'database_charset' => $server->database_charset,
            'service_host' => $server->service_host,
            'service_port' => $server->service_port,
        ];

        return array_map(
            static fn (mixed $value): string => hash('sha256', serialize($value)),
            $values,
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, bool>
     */
    public function connectionChanges(array $before, array $after): array
    {
        $changes = [];

        foreach (self::CONNECTION_CHANGE_LABELS as $field => $label) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$label] = true;
            }
        }

        return $changes;
    }

    private function loginDatabaseConfigured(LoginServer $server): bool
    {
        return trim((string) $server->driver) !== ''
            && trim((string) $server->database_host) !== ''
            && (int) $server->database_port > 0
            && trim((string) $server->database_name) !== ''
            && trim((string) $server->database_username) !== '';
    }

    private function serviceConfigured(LoginServer|GameServer $server): bool
    {
        return trim((string) $server->service_host) !== '' && (int) $server->service_port > 0;
    }
}
