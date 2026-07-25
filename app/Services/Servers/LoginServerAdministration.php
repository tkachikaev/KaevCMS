<?php

namespace App\Services\Servers;

use App\Models\LoginServer;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LoginServerAdministration
{
    public function __construct(
        private readonly ServerConnectionTester $tester,
        private readonly ServerDatabaseState $databaseState,
        private readonly AuditLogger $audit,
        private readonly ServerAuditValues $auditValues,
    ) {}

    /** @return array<string, mixed> */
    public function testStored(LoginServer $server): array
    {
        $report = $this->tester->testLoginServer($server);
        $this->databaseState->apply($server, $report);
        $this->auditConnectionTest($report, $server->name, $server);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function test(array $values, string $name, ?LoginServer $server = null): array
    {
        if ($server instanceof LoginServer && trim((string) ($values['database_password'] ?? '')) === '') {
            $values['database_password'] = $server->databasePassword() ?? '';
        }

        $report = $this->tester->testLoginValues($values);
        $this->auditConnectionTest($report, $name, $server);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{server: LoginServer, created: bool}
     */
    public function save(?LoginServer $server, array $values): array
    {
        $payload = $this->persistenceValues($values);

        if (! $server instanceof LoginServer) {
            $payload['database_password'] = trim((string) ($payload['database_password'] ?? '')) !== ''
                ? (string) $payload['database_password']
                : null;
            $server = LoginServer::query()->create($payload);
            $this->verifyStoredConnection($server);
            $server->refresh();

            $this->audit->success(
                category: 'admin',
                action: 'login_server.created',
                target: $server,
                details: ['values' => $this->auditValues->loginServer($server)],
            );

            return ['server' => $server, 'created' => true];
        }

        $before = $this->auditValues->loginServer($server);
        $beforeConnection = $this->auditValues->connectionFingerprints($server);
        if (trim((string) ($payload['database_password'] ?? '')) === '') {
            unset($payload['database_password']);
        }

        $server->update($payload);
        $server->refresh();
        $this->verifyStoredConnection($server);
        $server->refresh();

        $this->audit->success(
            category: 'admin',
            action: 'login_server.updated',
            target: $server,
            details: [
                'before' => $before,
                'after' => $this->auditValues->loginServer($server),
                'connection_changes' => $this->auditValues->connectionChanges(
                    $beforeConnection,
                    $this->auditValues->connectionFingerprints($server),
                ),
            ],
        );

        return ['server' => $server, 'created' => false];
    }

    /** @return array{name: string, values: array<string, mixed>}|null */
    public function delete(LoginServer $server): ?array
    {
        try {
            /** @var array{name: string, values: array<string, mixed>}|null $deleted */
            $deleted = DB::transaction(function () use ($server): ?array {
                $lockedServer = LoginServer::query()
                    ->lockForUpdate()
                    ->findOrFail($server->id);

                if ($lockedServer->gameServers()->exists() || $lockedServer->userGameAccounts()->exists()) {
                    return null;
                }

                $result = [
                    'name' => $lockedServer->name,
                    'values' => $this->auditValues->loginServer($lockedServer),
                ];
                $lockedServer->delete();

                return $result;
            });
        } catch (QueryException $exception) {
            if (! $this->isIntegrityConstraintViolation($exception)) {
                throw $exception;
            }

            $deleted = null;
        }

        if ($deleted !== null) {
            $this->audit->success(
                category: 'admin',
                action: 'login_server.deleted',
                target: $deleted['name'],
                details: ['values' => $deleted['values']],
            );
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function persistenceValues(array $values): array
    {
        $payload = [
            'name' => trim((string) ($values['name'] ?? '')),
            'driver' => trim((string) ($values['driver'] ?? '')),
            'database_host' => trim((string) ($values['database_host'] ?? '')),
            'database_port' => (int) ($values['database_port'] ?? 3306),
            'database_name' => trim((string) ($values['database_name'] ?? '')),
            'database_username' => trim((string) ($values['database_username'] ?? '')),
            'database_password' => (string) ($values['database_password'] ?? ''),
            'database_charset' => trim((string) ($values['database_charset'] ?? 'utf8mb4')),
            'monitor_status' => 'unknown',
            'monitor_failures' => 0,
            'monitor_checked_at' => null,
            'monitor_last_online_at' => null,
        ];

        if (array_key_exists('service_host', $values)) {
            $payload['service_host'] = $this->nullableString($values['service_host']);
        }

        if (array_key_exists('service_port', $values)) {
            $payload['service_port'] = (int) $values['service_port'];
        }

        return $payload;
    }

    private function verifyStoredConnection(LoginServer $server): void
    {
        try {
            $this->databaseState->apply($server, $this->tester->testLoginServer($server));
        } catch (Throwable) {
            $this->databaseState->markUnknown($server, 'check_failed');
        }
    }

    /** @param array<string, mixed> $report */
    private function auditConnectionTest(array $report, string $name, ?LoginServer $server): void
    {
        $details = [
            'driver' => $report['driver'] ?? null,
            'connected' => $report['connected'] ?? false,
            'compatible' => $report['compatible'] ?? null,
        ];

        if ($this->databaseState->isConfigured($report)) {
            $this->audit->success(
                category: 'admin',
                action: 'login_server.connection_tested',
                target: $server ?? $name,
                details: $details,
            );

            return;
        }

        $this->audit->failed(
            category: 'admin',
            action: 'login_server.connection_tested',
            target: $server ?? $name,
            details: $details,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isIntegrityConstraintViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        return str_starts_with($code, '23') || in_array($code, ['19', '1451'], true);
    }
}
