<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\SecurityLogMaintenance;
use App\Services\SecuritySettings;
use Illuminate\Console\Command;

class CleanupAuditLogsCommand extends Command
{
    protected $signature = 'kaevcms:logs-clean
        {--days= : Delete audit entries older than the specified number of days}
        {--admin-login-days= : Delete administrator login entries older than the specified number of days}
        {--dry-run : Show the number of entries without deleting them}';

    protected $aliases = ['l2forge:logs-clean'];

    protected $description = 'Remove expired audit and administrator login log entries';

    public function handle(
        AuditLogger $auditLogger,
        SecuritySettings $securitySettings,
        SecurityLogMaintenance $logs,
    ): int {
        $settings = $securitySettings->values();
        $auditDays = $this->option('days') !== null
            ? (int) $this->option('days')
            : $settings['audit_retention_days'];
        $adminLoginDays = $this->option('admin-login-days') !== null
            ? (int) $this->option('admin-login-days')
            : $settings['admin_login_retention_days'];

        if (! $this->validRetentionDays($auditDays) || ! $this->validRetentionDays($adminLoginDays)) {
            $this->error(__('The number of days must be between 1 and 3650.'));

            return self::FAILURE;
        }

        $statistics = $logs->statistics($auditDays, $adminLoginDays);

        if ($this->option('dry-run')) {
            $this->info(__('Audit log').': '.__('Entries to delete: :count.', [
                'count' => $statistics['audit_expired'],
            ]));
            $this->line(__('Audit log').': '.__('Retention threshold: :date.', [
                'date' => $statistics['audit_threshold']->format('d.m.Y H:i:s'),
            ]));
            $this->info(__('Administrator login log').': '.__('Entries to delete: :count.', [
                'count' => $statistics['admin_login_expired'],
            ]));
            $this->line(__('Administrator login log').': '.__('Retention threshold: :date.', [
                'date' => $statistics['admin_login_threshold']->format('d.m.Y H:i:s'),
            ]));

            return self::SUCCESS;
        }

        $result = $logs->cleanup($auditDays, $adminLoginDays);

        if ($result['audit_deleted'] > 0 || $result['admin_login_deleted'] > 0) {
            $auditLogger->system('system', 'audit.cleaned', details: [
                'audit_retention_days' => $auditDays,
                'audit_deleted_count' => $result['audit_deleted'],
                'admin_login_retention_days' => $adminLoginDays,
                'admin_login_deleted_count' => $result['admin_login_deleted'],
            ]);
        }

        $this->info(__('Audit log').': '.__('Deleted entries: :count.', [
            'count' => $result['audit_deleted'],
        ]));
        $this->info(__('Administrator login log').': '.__('Deleted entries: :count.', [
            'count' => $result['admin_login_deleted'],
        ]));

        return self::SUCCESS;
    }

    private function validRetentionDays(int $days): bool
    {
        return $days >= 1 && $days <= 3650;
    }
}
