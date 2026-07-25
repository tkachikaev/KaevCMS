<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminLoginLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AdminLoginService
{
    public function complete(
        Admin $admin,
        Request $request,
        bool $remember,
        AuditLogger $auditLogger,
        string $method = 'password',
    ): void {
        Auth::guard('admin')->login($admin, $remember);
        $request->session()->regenerate();

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'locale' => app()->getLocale(),
        ])->save();

        $request->session()->put('admin_session_version', $admin->session_version);

        $this->writeLoginLog($request, $admin->email, true, null, $admin);
        $auditLogger->success(
            category: 'admin',
            action: 'auth.login',
            actor: $admin,
            target: __('Control panel'),
            details: ['method' => $method],
        );
    }

    public function failed(
        Request $request,
        string $email,
        string $reason,
        ?Admin $admin,
        AuditLogger $auditLogger,
    ): void {
        $this->writeLoginLog($request, $email, false, $reason, $admin);

        if ($admin !== null) {
            $auditLogger->failed(
                category: 'admin',
                action: $reason === 'invalid_two_factor' ? 'auth.2fa_failed' : 'auth.login_failed',
                actor: $admin,
                target: __('Control panel'),
                details: ['reason' => $reason],
            );
        }
    }

    private function writeLoginLog(
        Request $request,
        string $email,
        bool $successful,
        ?string $failureReason,
        ?Admin $admin,
    ): void {
        AdminLoginLog::query()->create([
            'admin_id' => $admin?->id,
            'email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'successful' => $successful,
            'failure_reason' => $failureReason,
        ]);
    }
}
