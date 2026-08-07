<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use App\Models\LoginServer;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class ExternalDatabaseInformation
{
    public function __construct(private readonly ServerDriverRegistry $drivers) {}

    /** @return array{login_servers:list<array<string,mixed>>,game_servers:list<array<string,mixed>>,summaries:array{login:array<string,mixed>,game:array<string,mixed>},total:int} */
    public function collect(): array
    {
        $loginServers = LoginServer::query()
            ->orderBy('id')
            ->get()
            ->map(fn (LoginServer $server): array => $this->loginServer($server))
            ->values()
            ->all();
        $gameServers = GameServer::query()
            ->with(['translations', 'loginServer'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (GameServer $server): array => $this->gameServer($server))
            ->values()
            ->all();

        return [
            'login_servers' => $loginServers,
            'game_servers' => $gameServers,
            'summaries' => [
                'login' => $this->summary($loginServers),
                'game' => $this->summary($gameServers),
            ],
            'total' => count($loginServers) + count($gameServers),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $servers
     * @return array{total:int,available:int,attention:int,state:string,badge:string,status:string,details:string,checked_at:?CarbonInterface}
     */
    private function summary(array $servers): array
    {
        $total = count($servers);
        $available = 0;
        $attention = 0;
        $checkedAt = null;

        foreach ($servers as $server) {
            if (($server['status'] ?? null) === 'configured') {
                $available++;
            } elseif (($server['status'] ?? null) === 'not_configured') {
                $attention++;
            }

            $serverCheckedAt = $server['checked_at'] ?? null;
            if (
                $serverCheckedAt instanceof CarbonInterface
                && ($checkedAt === null || $serverCheckedAt->greaterThan($checkedAt))
            ) {
                $checkedAt = $serverCheckedAt;
            }
        }

        if ($total === 0) {
            return [
                'total' => 0,
                'available' => 0,
                'attention' => 0,
                'state' => 'none',
                'badge' => 'muted',
                'status' => (string) __('external_databases.summary.state.none'),
                'details' => (string) __('external_databases.summary.details.none'),
                'checked_at' => null,
            ];
        }

        if ($attention > 0) {
            $state = 'attention';
            $badge = 'danger';
            $status = (string) __('external_databases.summary.state.attention');
            $details = (string) __('external_databases.summary.details.attention', [
                'available' => $available,
                'total' => $total,
                'attention' => $attention,
            ]);
        } elseif ($available === $total) {
            $state = 'available';
            $badge = 'success';
            $status = (string) __('external_databases.summary.state.available');
            $details = (string) __('external_databases.summary.details.available', [
                'available' => $available,
                'total' => $total,
            ]);
        } else {
            $state = 'pending';
            $badge = 'warning';
            $status = (string) __('external_databases.summary.state.pending');
            $details = (string) __('external_databases.summary.details.pending', [
                'available' => $available,
                'total' => $total,
            ]);
        }

        return [
            'total' => $total,
            'available' => $available,
            'attention' => $attention,
            'state' => $state,
            'badge' => $badge,
            'status' => $status,
            'details' => $details,
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * @param  array{login_servers: list<array<string, mixed>>, game_servers: list<array<string, mixed>>, summaries: array{login: array<string, mixed>, game: array<string, mixed>}, total: int}  $information
     * @return list<string>
     */
    public function reportLines(array $information): array
    {
        $lines = [(string) __('external_databases.report_heading')];

        foreach ([
            (string) __('external_databases.login_servers') => $information['login_servers'],
            (string) __('external_databases.game_servers') => $information['game_servers'],
        ] as $group => $servers) {
            $lines[] = $group.':';

            if ($servers === []) {
                $lines[] = '  - '.__('external_databases.none_configured');

                continue;
            }

            foreach ($servers as $server) {
                $rawCapabilityLabels = $server['capability_labels'] ?? null;
                $capabilityLabels = is_array($rawCapabilityLabels)
                    ? array_map(static fn (mixed $value): string => (string) $value, $rawCapabilityLabels)
                    : [];
                $lines[] = sprintf(
                    '  - %s | %s | %s | %s | %s | %s',
                    (string) ($server['name'] ?? ''),
                    (string) ($server['driver_label'] ?? ''),
                    (string) ($server['status_label'] ?? ''),
                    (string) __('external_databases.latency_value', ['value' => $server['latency_ms'] ?? 'N/A']),
                    (string) __('external_databases.profile_value', ['value' => $server['profile_label'] ?? 'N/A']),
                    (string) __('external_databases.capabilities_value', [
                        'value' => $capabilityLabels !== []
                            ? implode(', ', $capabilityLabels)
                            : 'NONE',
                    ]),
                );
            }
        }

        return $lines;
    }

    /** @return array<string,mixed> */
    private function loginServer(LoginServer $server): array
    {
        $driver = $this->drivers->loginDriver($server->driver);

        return $this->server(
            type: 'login',
            name: $server->name,
            driverKey: $server->driver,
            driverLabel: (string) ($driver['label'] ?? Str::headline($server->driver)),
            databaseStatus: $server->database_status,
            databaseError: $server->database_error,
            checkedAt: $server->database_checked_at,
            lastSuccessAt: $server->database_last_success_at,
            lastErrorClass: $server->database_last_error_class,
            lastErrorAt: $server->database_last_error_at,
            latencyMs: $server->database_latency_ms,
            schemaProfile: $server->database_schema_profile,
            capabilities: $server->database_capabilities,
            tableChecks: $server->database_table_checks,
            monitorStatus: $server->monitor_status,
            monitorCheckedAt: $server->monitor_checked_at,
            usesLoginConnection: false,
        );
    }

    /** @return array<string,mixed> */
    private function gameServer(GameServer $server): array
    {
        $driverKey = (string) $server->driver;
        $driver = $this->drivers->gameDriver($driverKey);

        return $this->server(
            type: 'game',
            name: $server->nameFor(),
            driverKey: $driverKey,
            driverLabel: (string) ($driver['label'] ?? Str::headline($driverKey)),
            databaseStatus: $server->database_status,
            databaseError: $server->database_error,
            checkedAt: $server->database_checked_at,
            lastSuccessAt: $server->database_last_success_at,
            lastErrorClass: $server->database_last_error_class,
            lastErrorAt: $server->database_last_error_at,
            latencyMs: $server->database_latency_ms,
            schemaProfile: $server->database_schema_profile,
            capabilities: $server->database_capabilities,
            tableChecks: $server->database_table_checks,
            monitorStatus: $server->monitor_status,
            monitorCheckedAt: $server->monitor_checked_at,
            usesLoginConnection: $server->use_login_server_connection,
        );
    }

    /**
     * @param  list<string>|null  $capabilities
     * @param  list<array<string,mixed>>|null  $tableChecks
     * @return array<string,mixed>
     */
    private function server(
        string $type,
        string $name,
        string $driverKey,
        string $driverLabel,
        string $databaseStatus,
        ?string $databaseError,
        ?CarbonInterface $checkedAt,
        ?CarbonInterface $lastSuccessAt,
        ?string $lastErrorClass,
        ?CarbonInterface $lastErrorAt,
        ?int $latencyMs,
        ?string $schemaProfile,
        ?array $capabilities,
        ?array $tableChecks,
        string $monitorStatus,
        ?CarbonInterface $monitorCheckedAt,
        bool $usesLoginConnection,
    ): array {
        return [
            'type' => $type,
            'name' => trim($name),
            'driver' => $driverKey,
            'driver_label' => $driverLabel,
            'status' => $databaseStatus,
            'status_state' => $this->statusState($databaseStatus),
            'status_label' => $this->statusLabel($databaseStatus),
            'error_code' => $databaseError,
            'error_label' => $this->errorLabel($databaseError),
            'checked_at' => $checkedAt,
            'last_success_at' => $lastSuccessAt,
            'last_error_class' => $this->safeErrorClass($lastErrorClass),
            'last_error_at' => $lastErrorAt,
            'latency_ms' => $latencyMs,
            'schema_profile' => $schemaProfile,
            'profile_label' => $this->profileLabel($schemaProfile),
            'capabilities' => $this->capabilities($capabilities),
            'capability_labels' => array_map(
                fn (string $capability): string => $this->capabilityLabel($capability),
                $this->capabilities($capabilities),
            ),
            'tables' => $this->tables($tableChecks),
            'service_status' => $monitorStatus,
            'service_state' => $this->serviceState($monitorStatus),
            'service_label' => $this->serviceLabel($monitorStatus),
            'service_checked_at' => $monitorCheckedAt,
            'uses_login_connection' => $usesLoginConnection,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'configured' => (string) __('external_databases.status.configured'),
            'not_configured' => (string) __('external_databases.status.not_configured'),
            default => (string) __('external_databases.status.unknown'),
        };
    }

    private function errorLabel(?string $error): ?string
    {
        return match ($error) {
            'connection_failed' => (string) __('external_databases.error.connection_failed'),
            'driver_unavailable' => (string) __('external_databases.error.driver_unavailable'),
            'incompatible_schema' => (string) __('external_databases.error.incompatible_schema'),
            'check_failed' => (string) __('external_databases.error.check_failed'),
            default => null,
        };
    }

    private function serviceLabel(string $status): string
    {
        return match ($status) {
            'online' => (string) __('external_databases.service.online'),
            'offline' => (string) __('external_databases.service.offline'),
            default => (string) __('external_databases.service.unknown'),
        };
    }

    private function profileLabel(?string $profile): ?string
    {
        return match ($profile) {
            'mobius_interlude_plus' => (string) __('external_databases.profile.mobius_interlude_plus'),
            'mobius_legacy' => (string) __('external_databases.profile.mobius_legacy'),
            'mobius_modern' => (string) __('external_databases.profile.mobius_modern'),
            'unknown' => (string) __('external_databases.profile.unknown'),
            default => null,
        };
    }

    private function capabilityLabel(string $capability): string
    {
        return match ($capability) {
            'account_lookup' => (string) __('external_databases.capability.account_lookup'),
            'account_creation' => (string) __('external_databases.capability.account_creation'),
            'password_change' => (string) __('external_databases.capability.password_change'),
            'account_data' => (string) __('external_databases.capability.account_data'),
            'ip_authorization' => (string) __('external_databases.capability.ip_authorization'),
            'level' => (string) __('external_databases.capability.level'),
            'pvp' => (string) __('external_databases.capability.pvp'),
            'pk' => (string) __('external_databases.capability.pk'),
            'play_time' => (string) __('external_databases.capability.play_time'),
            'heroes' => (string) __('external_databases.capability.heroes'),
            'castles' => (string) __('external_databases.capability.castles'),
            'character_rescue' => (string) __('external_databases.capability.character_rescue'),
            default => Str::headline($capability),
        };
    }

    private function statusState(string $status): string
    {
        return match ($status) {
            'configured' => 'success',
            'not_configured' => 'danger',
            default => 'neutral',
        };
    }

    private function serviceState(string $status): string
    {
        return match ($status) {
            'online' => 'success',
            'offline' => 'danger',
            default => 'neutral',
        };
    }

    private function safeErrorClass(?string $errorClass): ?string
    {
        $errorClass = trim((string) $errorClass);

        return $errorClass !== '' ? class_basename($errorClass) : null;
    }

    /**
     * @param  list<string>|null  $values
     * @return list<string>
     */
    private function capabilities(?array $values): array
    {
        if ($values === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ))));
    }

    /**
     * @param  list<array<string, mixed>>|null  $checks
     * @return list<array<string, mixed>>
     */
    private function tables(?array $checks): array
    {
        if ($checks === null) {
            return [];
        }

        $tables = [];
        foreach ($checks as $check) {
            $required = (bool) ($check['required'] ?? false);
            $exists = (bool) ($check['table_exists'] ?? false);
            $missing = is_array($check['missing_columns'] ?? null)
                ? array_values(array_map(static fn (mixed $column): string => (string) $column, $check['missing_columns']))
                : [];
            $state = $exists && $missing === []
                ? 'success'
                : ($required ? 'danger' : 'neutral');

            $tables[] = [
                'name' => (string) ($check['table'] ?? ''),
                'required' => $required,
                'exists' => $exists,
                'missing_columns' => $missing,
                'state' => $state,
                'status' => match (true) {
                    ! $exists => $required
                        ? __('external_databases.table.required_missing')
                        : __('external_databases.table.optional_missing'),
                    $missing !== [] => __('external_databases.table.columns_missing'),
                    default => __('external_databases.table.available'),
                },
            ];
        }

        return $tables;
    }
}
