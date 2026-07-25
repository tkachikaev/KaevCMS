<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\SiteSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Throwable;

final class ServerStatusOverview
{
    public function __construct(
        private readonly ServerMonitorSettings $settings,
        private readonly SiteSettings $siteSettings,
    ) {}

    /** @return array<string, mixed> */
    public function get(?string $locale = null): array
    {
        $publicOnlineVisible = $this->siteSettings->showPublicOnline();
        $loginServers = LoginServer::query()->orderBy('id')->get();
        $loginStates = $loginServers->mapWithKeys(function (LoginServer $server): array {
            $databaseState = $this->databaseState($server->database_status, $server->database_checked_at);
            $serviceState = $this->serviceState($server->monitor_status, $server->monitor_checked_at);

            return [$server->id => [
                'database' => $databaseState,
                'service' => $serviceState,
                'state' => $this->operationalState($databaseState, $serviceState),
            ]];
        });

        $gameServers = GameServer::query()
            ->with(['translations', 'loginServer'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $games = $gameServers->map(function (GameServer $server) use ($locale, $loginStates, $publicOnlineVisible): array {
            $databaseState = $this->databaseState($server->database_status, $server->database_checked_at);
            $serviceState = $this->serviceState($server->monitor_status, $server->monitor_checked_at);
            $operationalState = $this->operationalState($databaseState, $serviceState);
            $loginStatesForServer = $server->login_server_id !== null
                ? $loginStates->get($server->login_server_id)
                : null;
            $baseAvailabilityState = $this->publicAvailabilityState(
                $databaseState,
                $serviceState,
                (string) data_get($loginStatesForServer, 'database', 'not_configured'),
                (string) data_get($loginStatesForServer, 'service', 'unknown'),
            );
            $maintenanceEnabled = (bool) $server->maintenance_enabled;
            $availabilityState = $maintenanceEnabled ? 'maintenance' : $baseAvailabilityState;
            $players = $baseAvailabilityState === 'online'
                && $this->isFresh($server->online_checked_at, $this->onlineStaleSeconds())
                ? max(0, (int) $server->online_players)
                : null;

            return [
                'id' => (int) $server->id,
                'name' => $server->nameFor($locale),
                'state' => $maintenanceEnabled ? 'maintenance' : $operationalState,
                'database_state' => $databaseState,
                'service_state' => $serviceState,
                'availability_state' => $availabilityState,
                'monitor_availability_state' => $baseAvailabilityState,
                'players' => $players,
                'public_players' => $publicOnlineVisible && $availabilityState === 'online' ? $players : null,
                'maintenance_enabled' => $maintenanceEnabled,
                'maintenance_message' => $maintenanceEnabled
                    ? $server->maintenanceMessageFor($locale)
                    : '',
                'checked_at' => $this->date($server->monitor_checked_at),
                'database_checked_at' => $this->date($server->database_checked_at),
            ];
        })->values();

        $logins = $loginServers->map(function (LoginServer $server) use ($loginStates): array {
            $states = $loginStates->get($server->id, [
                'database' => 'unknown',
                'service' => 'unknown',
                'state' => 'unknown',
            ]);

            return [
                'id' => (int) $server->id,
                'name' => $server->name,
                'state' => (string) $states['state'],
                'database_state' => (string) $states['database'],
                'service_state' => (string) $states['service'],
                'checked_at' => $this->date($server->monitor_checked_at),
                'database_checked_at' => $this->date($server->database_checked_at),
            ];
        })->values();

        return [
            'total_online' => $games->sum(fn (array $server): int => $server['players'] ?? 0),
            'public_online_visible' => $publicOnlineVisible,
            'partial' => $games->contains(
                fn (array $server): bool => $server['monitor_availability_state'] === 'unknown'
                    || ($server['monitor_availability_state'] === 'online' && $server['players'] === null),
            ),
            'checked_at' => $this->latestCheckedAt(
                $games->pluck('checked_at')->values()->all(),
                $games->pluck('database_checked_at')->values()->all(),
                $logins->pluck('checked_at')->values()->all(),
                $logins->pluck('database_checked_at')->values()->all(),
            ),
            'game_servers' => $games->all(),
            'login_servers' => $logins->all(),
        ];
    }

    private function databaseState(?string $status, mixed $checkedAt): string
    {
        if (! $this->isFresh($checkedAt, $this->statusStaleSeconds())) {
            return 'unknown';
        }

        return in_array($status, ['configured', 'not_configured'], true) ? $status : 'unknown';
    }

    private function serviceState(?string $status, mixed $checkedAt): string
    {
        if (! $this->isFresh($checkedAt, $this->statusStaleSeconds())) {
            return 'unknown';
        }

        return in_array($status, ['online', 'offline'], true) ? $status : 'unknown';
    }

    private function operationalState(string $databaseState, string $serviceState): string
    {
        if ($databaseState === 'not_configured') {
            return 'not_configured';
        }

        if ($databaseState !== 'configured') {
            return 'unknown';
        }

        return $serviceState === 'online' ? 'online' : 'configured';
    }

    private function publicAvailabilityState(
        string $gameDatabaseState,
        string $gameServiceState,
        string $loginDatabaseState,
        string $loginServiceState,
    ): string {
        if ($gameDatabaseState === 'configured'
            && $gameServiceState === 'online'
            && $loginDatabaseState === 'configured'
            && $loginServiceState === 'online') {
            return 'online';
        }

        if ($gameDatabaseState === 'not_configured'
            || $loginDatabaseState === 'not_configured'
            || $gameServiceState === 'offline'
            || $loginServiceState === 'offline') {
            return 'offline';
        }

        return 'unknown';
    }

    private function isFresh(mixed $value, int $seconds): bool
    {
        $date = $this->date($value);

        return $date instanceof CarbonInterface
            && $date->getTimestamp() >= now()->getTimestamp() - $seconds;
    }

    private function date(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int,mixed> ...$dateGroups */
    private function latestCheckedAt(array ...$dateGroups): ?CarbonInterface
    {
        $latest = null;

        foreach ($dateGroups as $dates) {
            foreach ($dates as $date) {
                if (! $date instanceof CarbonInterface) {
                    continue;
                }

                if (! $latest instanceof CarbonInterface
                    || $date->getTimestamp() > $latest->getTimestamp()) {
                    $latest = $date;
                }
            }
        }

        return $latest;
    }

    private function statusStaleSeconds(): int
    {
        $configured = max(60, min(3600, (int) config('cms.server_monitor.status_stale_seconds', 180)));

        return max($configured, $this->settings->refreshIntervalSeconds() + 60);
    }

    private function onlineStaleSeconds(): int
    {
        $configured = max(60, min(3600, (int) config('cms.server_monitor.online_stale_seconds', 300)));

        return max($configured, $this->settings->refreshIntervalSeconds() + 60);
    }
}
