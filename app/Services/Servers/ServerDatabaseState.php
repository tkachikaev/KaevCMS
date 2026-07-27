<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use App\Models\LoginServer;

final class ServerDatabaseState
{
    /** @param array<string,mixed> $report */
    public function apply(LoginServer|GameServer $server, array $report): bool
    {
        $configured = $this->isConfigured($report);
        $checkedAt = now();
        $values = [
            'database_status' => $configured ? 'configured' : 'not_configured',
            'database_error' => $configured ? null : $this->errorCode($report),
            'database_checked_at' => $checkedAt,
        ];

        if (($report['connected'] ?? false) === true) {
            $values['database_latency_ms'] = $this->latency($report['latency_ms'] ?? null);
            $values['database_schema_profile'] = $this->nullableString($report['schema_profile'] ?? null);
            $values['database_capabilities'] = $this->capabilities($report['capabilities'] ?? []);
            $values['database_table_checks'] = $this->tableChecks($report['checks'] ?? []);
        }

        if ($configured) {
            $values['database_last_success_at'] = $checkedAt;
        } else {
            $values['database_last_error_class'] = $this->nullableString($report['error_class'] ?? null);
            $values['database_last_error_at'] = $checkedAt;
        }

        $server->forceFill($values)->save();

        return $configured;
    }

    public function markUnknown(
        LoginServer|GameServer $server,
        ?string $error = null,
        ?string $errorClass = null,
    ): void {
        $recordedAt = $errorClass !== null && trim($errorClass) !== '' ? now() : null;
        $values = [
            'database_status' => 'unknown',
            'database_error' => $error,
            'database_checked_at' => $recordedAt,
        ];

        if ($recordedAt !== null) {
            $values['database_last_error_class'] = trim((string) $errorClass);
            $values['database_last_error_at'] = $recordedAt;
        }

        $server->forceFill($values)->save();
    }

    /** @param array<string,mixed> $report */
    public function isConfigured(array $report): bool
    {
        return ($report['connected'] ?? false) === true
            && ($report['driver_ready'] ?? false) === true
            && ($report['compatible'] ?? false) === true;
    }

    /** @param array<string,mixed> $report */
    private function errorCode(array $report): string
    {
        if (($report['connected'] ?? false) !== true) {
            return 'connection_failed';
        }

        if (($report['driver_ready'] ?? false) !== true) {
            return 'driver_unavailable';
        }

        return 'incompatible_schema';
    }

    private function latency(mixed $value): ?int
    {
        if (! is_int($value) && ! is_numeric($value)) {
            return null;
        }

        return max(0, min(4_294_967_295, (int) $value));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private function capabilities(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $capabilities = [];
        foreach ($values as $value) {
            $capability = trim((string) $value);
            if ($capability !== '') {
                $capabilities[] = $capability;
            }
        }

        return array_values(array_unique($capabilities));
    }

    /** @return list<array{table:string,required:bool,table_exists:bool,missing_columns:list<string>}> */
    private function tableChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            return [];
        }

        $result = [];
        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            $table = trim((string) ($check['table'] ?? ''));
            if ($table === '') {
                continue;
            }

            $missingColumns = [];
            foreach (is_array($check['missing_columns'] ?? null) ? $check['missing_columns'] : [] as $column) {
                $column = trim((string) $column);
                if ($column !== '') {
                    $missingColumns[] = $column;
                }
            }

            $result[] = [
                'table' => $table,
                'required' => (bool) ($check['required'] ?? false),
                'table_exists' => (bool) ($check['table_exists'] ?? false),
                'missing_columns' => array_values(array_unique($missingColumns)),
            ];
        }

        return $result;
    }
}
