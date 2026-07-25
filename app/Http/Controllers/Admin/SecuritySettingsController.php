<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CleanupSecurityLogsRequest;
use App\Http\Requests\Admin\SaveSecuritySettingsRequest;
use App\Models\Admin;
use App\Models\AdminLoginLog;
use App\Services\AuditLogger;
use App\Services\SecurityLogMaintenance;
use App\Services\SecuritySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SecuritySettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(SecuritySettings $settings, SecurityLogMaintenance $logs): View
    {
        return view('admin.settings.security', [
            'settings' => $settings->values(),
            'statistics' => $logs->statistics(),
            'loginAttempts' => AdminLoginLog::query()
                ->with('admin:id,name,email')
                ->latest()
                ->paginate(25, ['*'], 'login_page')
                ->withQueryString(),
        ]);
    }

    public function update(
        SaveSecuritySettingsRequest $request,
        SecuritySettings $settings,
    ): RedirectResponse {
        $before = $settings->values();
        $validated = $request->validated();
        $settings->update([
            'login_ip_per_minute' => (int) $validated['login_ip_per_minute'],
            'login_ip_per_hour' => (int) $validated['login_ip_per_hour'],
            'login_max_attempts' => (int) $validated['login_max_attempts'],
            'login_decay_minutes' => (int) $validated['login_decay_minutes'],
            'audit_retention_days' => (int) $validated['audit_retention_days'],
            'admin_login_retention_days' => (int) $validated['admin_login_retention_days'],
        ]);
        $after = $settings->values();

        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.security_updated',
            target: __('Security settings'),
            details: [
                'before' => $this->auditValues($before),
                'after' => $this->auditValues($after),
            ],
        );

        return redirect()
            ->route('admin.settings.security')
            ->with('status', __('Security settings saved.'));
    }

    public function cleanup(
        CleanupSecurityLogsRequest $request,
        SecuritySettings $settings,
        SecurityLogMaintenance $logs,
    ): RedirectResponse {
        $admin = $request->user('admin');

        if (! $admin instanceof Admin || ! Hash::check((string) $request->validated('current_password'), $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current administrator password is incorrect.'),
            ]);
        }

        $retention = $settings->values();
        $result = $logs->cleanup();

        $this->auditLogger->success(
            category: 'admin',
            action: 'security.logs_cleaned',
            actor: $admin,
            target: __('Security logs'),
            details: [
                'audit_retention_days' => $retention['audit_retention_days'],
                'audit_deleted_count' => $result['audit_deleted'],
                'admin_login_retention_days' => $retention['admin_login_retention_days'],
                'admin_login_deleted_count' => $result['admin_login_deleted'],
            ],
        );

        return redirect()
            ->route('admin.settings.security')
            ->with('status', __('Expired log records were deleted: :audit audit entries and :login sign-in entries.', [
                'audit' => $result['audit_deleted'],
                'login' => $result['admin_login_deleted'],
            ]));
    }

    /**
     * @param  array{
     *     login_max_attempts: int,
     *     login_decay_minutes: int,
     *     login_decay_seconds: int,
     *     login_ip_per_minute: int,
     *     login_ip_per_hour: int,
     *     audit_retention_days: int,
     *     admin_login_retention_days: int,
     *     logs_last_cleaned_at: string|null
     * }  $values
     * @return array<string, int>
     */
    private function auditValues(array $values): array
    {
        return [
            'login_ip_per_minute' => $values['login_ip_per_minute'],
            'login_ip_per_hour' => $values['login_ip_per_hour'],
            'login_max_attempts' => $values['login_max_attempts'],
            'login_decay_minutes' => $values['login_decay_minutes'],
            'audit_retention_days' => $values['audit_retention_days'],
            'admin_login_retention_days' => $values['admin_login_retention_days'],
        ];
    }
}
