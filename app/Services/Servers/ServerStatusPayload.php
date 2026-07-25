<?php

namespace App\Services\Servers;

use Carbon\CarbonInterface;

final class ServerStatusPayload
{
    /**
     * @param  array<string, mixed>  $monitor
     * @return array<string, mixed>
     */
    public function forPublic(array $monitor): array
    {
        $publicOnlineVisible = (bool) ($monitor['public_online_visible'] ?? true);
        $games = array_values(array_map(
            fn (mixed $server): array => $this->publicGame((array) $server, $publicOnlineVisible),
            (array) ($monitor['game_servers'] ?? []),
        ));
        $publicTotalOnline = $publicOnlineVisible
            ? array_sum(array_map(
                static fn (array $server): int => $server['public_players'] ?? 0,
                $games,
            ))
            : null;

        return [
            'total_online' => $publicTotalOnline,
            'total_online_formatted' => $publicTotalOnline !== null
                ? number_format($publicTotalOnline, 0, '.', ' ')
                : null,
            'public_online_visible' => $publicOnlineVisible,
            'partial' => (bool) ($monitor['partial'] ?? false),
            'updated_label' => $this->updatedLabel($monitor['checked_at'] ?? null),
            'game_servers' => $games,
        ];
    }

    /**
     * @param  array<string, mixed>  $monitor
     * @return array<string, mixed>
     */
    public function forAdmin(array $monitor): array
    {
        $games = array_values(array_map(
            fn (mixed $server): array => $this->adminGame((array) $server),
            (array) ($monitor['game_servers'] ?? []),
        ));
        $logins = array_values(array_map(
            fn (mixed $server): array => $this->adminLogin((array) $server),
            (array) ($monitor['login_servers'] ?? []),
        ));
        $totalOnline = max(0, (int) ($monitor['total_online'] ?? 0));

        return [
            'total_online' => $totalOnline,
            'total_online_formatted' => number_format($totalOnline, 0, '.', ' '),
            'partial' => (bool) ($monitor['partial'] ?? false),
            'updated_label' => $this->updatedLabel($monitor['checked_at'] ?? null),
            'game_servers' => $games,
            'login_servers' => $logins,
        ];
    }

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    private function publicGame(array $server, bool $publicOnlineVisible): array
    {
        $state = (string) ($server['availability_state'] ?? 'unknown');
        $players = isset($server['public_players'])
            ? max(0, (int) $server['public_players'])
            : null;

        return [
            'id' => (int) ($server['id'] ?? 0),
            'availability_state' => $state,
            'public_players' => $players,
            'maintenance_message' => (string) ($server['maintenance_message'] ?? ''),
            'public_state_label' => $this->publicStateLabel($state),
            'public_online_label' => ! $publicOnlineVisible
                ? null
                : ($players !== null
                    ? __('Online: :count', ['count' => number_format($players, 0, '.', ' ')])
                    : __('Online temporarily unavailable')),
        ];
    }

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    private function adminGame(array $server): array
    {
        $state = (string) ($server['state'] ?? 'unknown');
        $players = isset($server['players'])
            ? max(0, (int) $server['players'])
            : null;

        return [
            'id' => (int) ($server['id'] ?? 0),
            'state' => $state,
            'database_state' => (string) ($server['database_state'] ?? 'unknown'),
            'service_state' => (string) ($server['service_state'] ?? 'unknown'),
            'players' => $players,
            'admin_state_label' => $this->stateLabel($state),
            'details_label' => $this->detailsLabel($server),
            'admin_online_label' => $players !== null
                ? __(':count online', ['count' => number_format($players, 0, '.', ' ')])
                : '—',
        ];
    }

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    private function adminLogin(array $server): array
    {
        $state = (string) ($server['state'] ?? 'unknown');

        return [
            'id' => (int) ($server['id'] ?? 0),
            'state' => $state,
            'database_state' => (string) ($server['database_state'] ?? 'unknown'),
            'service_state' => (string) ($server['service_state'] ?? 'unknown'),
            'state_label' => $this->stateLabel($state),
            'details_label' => $this->detailsLabel($server),
        ];
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'maintenance' => __('Maintenance'),
            'online' => __('Server online'),
            'configured' => __('Configured'),
            'not_configured' => __('Not configured'),
            default => __('Status pending'),
        };
    }

    private function publicStateLabel(string $state): string
    {
        return match ($state) {
            'maintenance' => __('Maintenance'),
            'online' => __('Available'),
            'offline' => __('Unavailable'),
            default => __('Status pending'),
        };
    }

    /** @param  array<string, mixed>  $server */
    private function detailsLabel(array $server): string
    {
        return __('Database: :database · Service: :service', [
            'database' => $this->databaseLabel((string) ($server['database_state'] ?? 'unknown')),
            'service' => $this->serviceLabel((string) ($server['service_state'] ?? 'unknown')),
        ]);
    }

    private function databaseLabel(string $state): string
    {
        return match ($state) {
            'configured' => __('Connected'),
            'not_configured' => __('Connection failed'),
            default => __('Status pending'),
        };
    }

    private function serviceLabel(string $state): string
    {
        return match ($state) {
            'online' => __('Running'),
            'offline' => __('Unavailable'),
            default => __('Status pending'),
        };
    }

    private function updatedLabel(mixed $checkedAt): string
    {
        return $checkedAt instanceof CarbonInterface
            ? __('Updated :time', ['time' => $checkedAt->diffForHumans()])
            : __('Not checked yet');
    }
}
