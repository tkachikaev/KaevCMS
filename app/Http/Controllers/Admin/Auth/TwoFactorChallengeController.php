<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AdminLoginService;
use App\Services\AdminTwoFactorAuthentication;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    private const SESSION_KEY = 'admin_two_factor_challenge';

    public function create(Request $request): View|RedirectResponse
    {
        $challenge = $this->challenge($request);

        if ($challenge === null) {
            return redirect()->route('admin.login');
        }

        return view('admin.auth.two-factor-challenge', [
            'administratorEmail' => $challenge['email'],
        ]);
    }

    public function store(
        Request $request,
        AdminTwoFactorAuthentication $twoFactor,
        AdminLoginService $loginService,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $challenge = $this->challenge($request);

        if ($challenge === null) {
            return redirect()->route('admin.login');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $admin = Admin::query()->find($challenge['admin_id']);

        if ($admin === null || ! $admin->is_active || ! $admin->twoFactorEnabled()) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('admin.login')->with('status', __('The sign-in request expired. Please try again.'));
        }

        $secret = $admin->twoFactorSecret();

        if ($secret === null) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('admin.login')->with('status', __('Two-factor authentication data cannot be decrypted. Contact the server owner.'));
        }

        $code = trim((string) $validated['code']);
        $method = 'two_factor';
        $valid = preg_match('/^\d{6}$/', $code) === 1
            && $twoFactor->verify($secret, $code);

        if (! $valid && preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/i', $code) === 1) {
            $valid = $twoFactor->consumeRecoveryCode($admin, $code);
            $method = 'recovery_code';
        }

        if (! $valid) {
            $loginService->failed($request, $admin->email, 'invalid_two_factor', $admin, $auditLogger);

            throw ValidationException::withMessages([
                'code' => __('The authentication code is invalid or expired.'),
            ]);
        }

        $request->session()->forget(self::SESSION_KEY);
        $loginService->complete($admin, $request, $challenge['remember'], $auditLogger, $method);

        if ($method === 'recovery_code') {
            $auditLogger->success(
                category: 'admin',
                action: 'auth.2fa_recovery_used',
                actor: $admin,
                target: __('Control panel'),
            );
        } else {
            $auditLogger->success(
                category: 'admin',
                action: 'auth.2fa_succeeded',
                actor: $admin,
                target: __('Control panel'),
            );
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('admin.login');
    }

    /** @return array{admin_id: int, email: string, remember: bool, expires_at: int}|null */
    private function challenge(Request $request): ?array
    {
        $challenge = $request->session()->get(self::SESSION_KEY);

        if (! is_array($challenge)
            || ! isset($challenge['admin_id'], $challenge['email'], $challenge['remember'], $challenge['expires_at'])
            || ! is_int($challenge['admin_id'])
            || ! is_string($challenge['email'])
            || ! is_bool($challenge['remember'])
            || ! is_int($challenge['expires_at'])
            || $challenge['expires_at'] < now()->timestamp
        ) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $challenge;
    }
}
