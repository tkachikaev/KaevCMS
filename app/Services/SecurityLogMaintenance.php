<?php

namespace App\Services;

use App\Models\AdminLoginLog;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

final class SecurityLogMaintenance
{
    private const BATCH_SIZE = 1000;

    public function __construct(private readonly SecuritySettings $settings) {}

    /**
     * @return array{
     *     audit_total: int,
     *     audit_expired: int,
     *     audit_retention_days: int,
     *     audit_threshold: CarbonInterface,
     *     admin_login_total: int,
     *     admin_login_expired: int,
     *     admin_login_retention_days: int,
     *     admin_login_threshold: CarbonInterface,
     *     last_cleaned_at: CarbonInterface|null
     * }
     */
    public function statistics(?int $auditDays = null, ?int $adminLoginDays = null): array
    {
        $settings = $this->settings->values();
        $auditRetention = $auditDays ?? $settings['audit_retention_days'];
        $adminLoginRetention = $adminLoginDays ?? $settings['admin_login_retention_days'];
        $auditThreshold = now()->subDays($auditRetention);
        $adminLoginThreshold = now()->subDays($adminLoginRetention);

        return [
            'audit_total' => AuditLog::query()->count(),
            'audit_expired' => AuditLog::query()->where('created_at', '<', $auditThreshold)->count(),
            'audit_retention_days' => $auditRetention,
            'audit_threshold' => $auditThreshold,
            'admin_login_total' => AdminLoginLog::query()->count(),
            'admin_login_expired' => AdminLoginLog::query()->where('created_at', '<', $adminLoginThreshold)->count(),
            'admin_login_retention_days' => $adminLoginRetention,
            'admin_login_threshold' => $adminLoginThreshold,
            'last_cleaned_at' => $this->parseDate($settings['logs_last_cleaned_at']),
        ];
    }

    /** @return array{audit_deleted: int, admin_login_deleted: int, cleaned_at: string} */
    public function cleanup(?int $auditDays = null, ?int $adminLoginDays = null): array
    {
        $statistics = $this->statistics($auditDays, $adminLoginDays);
        $auditDeleted = $this->deleteAuditLogs($statistics['audit_threshold']);
        $adminLoginDeleted = $this->deleteAdminLoginLogs($statistics['admin_login_threshold']);

        return [
            'audit_deleted' => $auditDeleted,
            'admin_login_deleted' => $adminLoginDeleted,
            'cleaned_at' => $this->settings->markLogsCleaned(),
        ];
    }

    private function parseDate(?string $value): ?CarbonInterface
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function deleteAuditLogs(CarbonInterface $threshold): int
    {
        $deleted = 0;

        do {
            $ids = AuditLog::query()
                ->where('created_at', '<', $threshold)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += AuditLog::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === self::BATCH_SIZE);

        return $deleted;
    }

    private function deleteAdminLoginLogs(CarbonInterface $threshold): int
    {
        $deleted = 0;

        do {
            $ids = AdminLoginLog::query()
                ->where('created_at', '<', $threshold)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += AdminLoginLog::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === self::BATCH_SIZE);

        return $deleted;
    }
}
