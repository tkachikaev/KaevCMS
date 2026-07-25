<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\AuditLogger;
use App\Services\GameServerDeletionImpact;
use App\Services\GameServerSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class GameServerAdministration
{
    public const CONNECTION_CONNECT = 'connect';

    public const CONNECTION_DISCONNECT = 'disconnect';

    public const CONNECTION_PRESERVE = 'preserve';

    public function __construct(
        private readonly GameServerSettings $settings,
        private readonly GameServerDeletionImpact $deletionImpact,
        private readonly ServerConnectionTester $tester,
        private readonly ServerDatabaseState $databaseState,
        private readonly AuditLogger $audit,
        private readonly ServerAuditValues $auditValues,
    ) {}

    /** @return array<string, mixed> */
    public function testStored(GameServer $server): array
    {
        $server->loadMissing('loginServer');
        $loginServer = $server->loginServer;

        if (! $loginServer instanceof LoginServer) {
            $report = [
                'connected' => false,
                'compatible' => null,
                'driver_ready' => false,
                'error' => 'login_server_missing',
                'checks' => [],
            ];
            $this->databaseState->apply($server, $report);

            return $report;
        }

        $report = $this->tester->testGameServer($server);
        $this->databaseState->apply($server, $report);
        $this->auditConnectionTest($report, $server, $loginServer);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function test(
        array $values,
        LoginServer $loginServer,
        ?GameServer $server,
        string $targetName,
    ): array {
        if ($server instanceof GameServer
            && ! (bool) ($values['use_login_server_connection'] ?? true)
            && trim((string) ($values['database_password'] ?? '')) === '') {
            $values['database_password'] = $server->databasePassword() ?? '';
        }

        $report = $this->tester->testGameValues($values, $loginServer);
        $this->auditConnectionTest($report, $server ?? $targetName, $loginServer);

        return $report;
    }

    /**
     * @param  array{name: string, rates?: string|null, chronicle?: string|null, mode?: string|null, translations?: array<string, string>, maintenance_enabled?: bool, maintenance_messages?: array<string, string>, statistics_enabled?: bool, statistics_level_enabled?: bool, statistics_pvp_enabled?: bool, statistics_pk_enabled?: bool, statistics_play_time_enabled?: bool, statistics_heroes_enabled?: bool, statistics_castles_enabled?: bool, statistics_level_limit?: int, statistics_pvp_limit?: int, statistics_pk_limit?: int, statistics_play_time_limit?: int}  $profileValues
     * @param  array<string, mixed>|null  $connectionValues
     * @return array{server: GameServer, created: bool}
     */
    public function save(
        ?GameServer $server,
        array $profileValues,
        string $connectionMode = self::CONNECTION_PRESERVE,
        ?array $connectionValues = null,
    ): array {
        if (! in_array($connectionMode, [self::CONNECTION_CONNECT, self::CONNECTION_DISCONNECT, self::CONNECTION_PRESERVE], true)) {
            throw new InvalidArgumentException('Unsupported GameServer connection update mode.');
        }

        $created = ! $server instanceof GameServer;
        $before = $server instanceof GameServer ? $this->auditValues->gameServer($server) : null;
        $beforeConnection = $server instanceof GameServer
            ? $this->auditValues->connectionFingerprints($server)
            : null;

        $server = DB::transaction(function () use ($server, $profileValues, $connectionMode, $connectionValues): GameServer {
            if ($server instanceof GameServer) {
                $this->settings->update($server, $profileValues);
                $server->refresh();
            } else {
                $server = $this->settings->create($profileValues);
            }

            if ($connectionMode === self::CONNECTION_CONNECT) {
                if (! is_array($connectionValues)) {
                    throw new InvalidArgumentException('GameServer connection values are required.');
                }

                $this->applyConnection($server, $connectionValues);
            } elseif ($connectionMode === self::CONNECTION_DISCONNECT) {
                $this->disconnect($server);
            }

            return $server->fresh(['translations', 'loginServer']) ?? $server;
        });

        if ($connectionMode === self::CONNECTION_CONNECT) {
            $this->verifyStoredConnection($server);
            $server->refresh();
        } elseif ($connectionMode === self::CONNECTION_DISCONNECT) {
            $this->databaseState->markUnknown($server);
            $server->refresh();
        }

        if ($created) {
            $this->audit->success(
                category: 'admin',
                action: 'game_server.created',
                target: $server,
                details: ['values' => $this->auditValues->gameServer($server)],
            );
        } else {
            $afterConnection = $this->auditValues->connectionFingerprints($server);
            $this->audit->success(
                category: 'admin',
                action: 'game_server.updated',
                target: $server,
                details: [
                    'before' => $before,
                    'after' => $this->auditValues->gameServer($server),
                    'connection_changes' => is_array($beforeConnection)
                        ? $this->auditValues->connectionChanges($beforeConnection, $afterConnection)
                        : [],
                ],
            );
        }

        return ['server' => $server, 'created' => $created];
    }

    /** @param array<string, mixed> $connectionValues */
    public function updateConnection(GameServer $server, array $connectionValues): GameServer
    {
        $before = $this->auditValues->gameServer($server);
        $beforeConnection = $this->auditValues->connectionFingerprints($server);

        DB::transaction(function () use ($server, $connectionValues): void {
            $lockedServer = GameServer::query()
                ->lockForUpdate()
                ->findOrFail($server->id);
            $this->applyConnection($lockedServer, $connectionValues);
        });

        $server->refresh();
        $this->verifyStoredConnection($server);
        $server->refresh();
        $this->audit->success(
            category: 'admin',
            action: 'game_server.connection_updated',
            target: $server,
            details: [
                'before' => $before,
                'after' => $this->auditValues->gameServer($server),
                'connection_changes' => $this->auditValues->connectionChanges(
                    $beforeConnection,
                    $this->auditValues->connectionFingerprints($server),
                ),
            ],
        );

        return $server;
    }

    /**
     * @return array{
     *     game_server_id: int,
     *     login_server_id: int|null,
     *     login_server_name: string|null,
     *     replacement_game_server_id: int|null,
     *     login_server_account_count: int,
     *     accounts_becoming_unavailable: int,
     *     unavailable_after_deletion: int,
     *     requires_confirmation: bool,
     *     fingerprint: string
     * }
     */
    public function analyzeDeletion(GameServer $server): array
    {
        return $this->deletionImpact->analyze($server);
    }

    /**
     * @return array{
     *     game_server_id: int,
     *     login_server_id: int|null,
     *     login_server_name: string|null,
     *     replacement_game_server_id: int|null,
     *     login_server_account_count: int,
     *     accounts_becoming_unavailable: int,
     *     unavailable_after_deletion: int,
     *     requires_confirmation: bool,
     *     fingerprint: string
     * }
     */
    public function delete(GameServer $server, ?string $confirmedImpactFingerprint = null): array
    {
        $server->loadMissing(['translations', 'loginServer']);
        $name = $server->name;
        $serverId = $server->id;
        $values = $this->auditValues->gameServer($server);
        $impact = $this->settings->delete($server, $confirmedImpactFingerprint);

        $this->audit->success(
            category: 'admin',
            action: 'game_server.deleted',
            target: $name,
            details: [
                'game_server_id' => $serverId,
                'values' => $values,
                'deletion_impact' => [
                    'login_server_id' => $impact['login_server_id'],
                    'replacement_game_server_id' => $impact['replacement_game_server_id'],
                    'accounts_becoming_unavailable' => $impact['accounts_becoming_unavailable'],
                    'unavailable_after_deletion' => $impact['unavailable_after_deletion'],
                ],
            ],
        );

        return $impact;
    }

    /** @param array<string, mixed> $values */
    private function applyConnection(GameServer $server, array $values): void
    {
        $loginServer = LoginServer::query()->findOrFail((int) ($values['login_server_id'] ?? 0));
        $useLoginConnection = (bool) ($values['use_login_server_connection'] ?? true);
        $password = (string) ($values['database_password'] ?? '');
        $this->settings->reassignLinkedAccountsBeforeDisconnect($server, $loginServer->id);

        $payload = [
            'login_server_id' => $loginServer->id,
            'driver' => trim((string) ($values['driver'] ?? '')),
            'use_login_server_connection' => $useLoginConnection,
            'database_host' => $useLoginConnection ? null : trim((string) ($values['database_host'] ?? '')),
            'database_port' => $useLoginConnection ? null : (int) ($values['database_port'] ?? 3306),
            'database_name' => $useLoginConnection ? null : trim((string) ($values['database_name'] ?? '')),
            'database_username' => $useLoginConnection ? null : trim((string) ($values['database_username'] ?? '')),
            'database_charset' => $useLoginConnection ? null : trim((string) ($values['database_charset'] ?? 'utf8mb4')),
            'database_status' => 'unknown',
            'database_error' => null,
            'database_checked_at' => null,
            'monitor_status' => 'unknown',
            'monitor_failures' => 0,
            'monitor_checked_at' => null,
            'monitor_last_online_at' => null,
            'online_players' => null,
            'online_checked_at' => null,
        ];

        if (array_key_exists('service_host', $values)) {
            $payload['service_host'] = $this->nullableString($values['service_host']);
        }

        if (array_key_exists('service_port', $values)) {
            $payload['service_port'] = (int) $values['service_port'];
        }

        if ($useLoginConnection) {
            $payload['database_password'] = null;
        } elseif ($password !== '') {
            $payload['database_password'] = $password;
        }

        $server->update($payload);
        $this->settings->restoreOrphanedAccountLinks($server);
    }

    private function disconnect(GameServer $server): void
    {
        $this->settings->reassignLinkedAccountsBeforeDisconnect($server);
        $server->update([
            'login_server_id' => null,
            'driver' => null,
            'use_login_server_connection' => true,
            'database_host' => null,
            'database_port' => null,
            'database_name' => null,
            'database_username' => null,
            'database_password' => null,
            'database_charset' => null,
            'service_host' => null,
            'service_port' => null,
            'database_status' => 'unknown',
            'database_error' => null,
            'database_checked_at' => null,
            'monitor_status' => 'unknown',
            'monitor_failures' => 0,
            'monitor_checked_at' => null,
            'monitor_last_online_at' => null,
            'online_players' => null,
            'online_checked_at' => null,
        ]);
    }

    private function verifyStoredConnection(GameServer $server): void
    {
        try {
            $server->loadMissing('loginServer');
            $this->databaseState->apply($server, $this->tester->testGameServer($server));
        } catch (Throwable) {
            $this->databaseState->markUnknown($server, 'check_failed');
        }
    }

    /** @param array<string, mixed> $report */
    private function auditConnectionTest(
        array $report,
        GameServer|string $target,
        LoginServer $loginServer,
    ): void {
        $details = [
            'login_server_id' => $loginServer->id,
            'driver' => $report['driver'] ?? null,
            'connected' => $report['connected'] ?? false,
            'compatible' => $report['compatible'] ?? null,
        ];

        if ($this->databaseState->isConfigured($report)) {
            $this->audit->success(
                category: 'admin',
                action: 'game_server.connection_tested',
                target: $target,
                details: $details,
            );
        } else {
            $this->audit->failed(
                category: 'admin',
                action: 'game_server.connection_tested',
                target: $target,
                details: $details,
            );
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
