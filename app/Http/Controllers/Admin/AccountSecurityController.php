<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AdminTwoFactorAuthentication;
use App\Services\AuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountSecurityController extends Controller
{
    private const SETUP_SESSION_KEY = 'admin_two_factor_setup';

    public function show(Request $request, AdminTwoFactorAuthentication $twoFactor): View
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $setup = $this->setup($request);

        return view('admin.account.security', [
            'administrator' => $admin,
            'setupSecret' => $setup['secret'] ?? null,
            'provisioningUri' => $setup !== null ? $twoFactor->provisioningUri($admin, $setup['secret']) : null,
            'recoveryCodes' => $this->pullRecoveryCodes($request),
        ]);
    }

    public function begin(
        Request $request,
        AdminTwoFactorAuthentication $twoFactor,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        if ($admin->twoFactorEnabled()) {
            return back()->with('status', __('Two-factor authentication is already enabled.'));
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
        ]);

        $this->ensureCurrentPassword($admin, (string) $validated['current_password']);

        $request->session()->put(self::SETUP_SESSION_KEY, [
            'secret' => Crypt::encryptString($twoFactor->generateSecret()),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);

        $auditLogger->success(
            category: 'admin',
            action: 'auth.2fa_setup_started',
            actor: $admin,
            target: $admin,
        );

        return redirect()->route('admin.account.security');
    }

    public function confirm(
        Request $request,
        AdminTwoFactorAuthentication $twoFactor,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $setup = $this->setup($request);

        if ($setup === null) {
            throw ValidationException::withMessages([
                'code' => __('The setup session expired. Start two-factor authentication setup again.'),
            ]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        if (! $twoFactor->verify($setup['secret'], (string) $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => __('The authentication code is invalid or expired.'),
            ]);
        }

        $codes = $twoFactor->generateRecoveryCodes();
        $admin->forceFill([
            'two_factor_secret' => $setup['secret'],
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes),
            'two_factor_confirmed_at' => now(),
            'session_version' => $admin->session_version + 1,
            'remember_token' => Str::random(60),
        ])->save();

        $request->session()->regenerate();
        $request->session()->put('admin_session_version', $admin->session_version);
        $request->session()->forget(self::SETUP_SESSION_KEY);
        $this->storeRecoveryCodes($request, $codes);

        $auditLogger->success(
            category: 'admin',
            action: 'auth.2fa_enabled',
            actor: $admin,
            target: $admin,
            details: ['other_sessions_invalidated' => true],
        );

        return redirect()->route('admin.account.security')->with('status', __('Two-factor authentication was enabled.'));
    }

    public function regenerateRecoveryCodes(
        Request $request,
        AdminTwoFactorAuthentication $twoFactor,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $this->ensureEnabled($admin);

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $this->ensureCurrentPassword($admin, (string) $validated['current_password']);

        $secret = $this->enabledSecret($admin);

        if (! $twoFactor->verify($secret, (string) $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => __('The authentication code is invalid or expired.'),
            ]);
        }

        $codes = $twoFactor->generateRecoveryCodes();
        $admin->forceFill([
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes),
        ])->save();
        $this->storeRecoveryCodes($request, $codes);

        $auditLogger->success(
            category: 'admin',
            action: 'auth.2fa_recovery_regenerated',
            actor: $admin,
            target: $admin,
        );

        return redirect()->route('admin.account.security')->with('status', __('New recovery codes were generated.'));
    }

    public function disable(
        Request $request,
        AdminTwoFactorAuthentication $twoFactor,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $this->ensureEnabled($admin);

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $this->ensureCurrentPassword($admin, (string) $validated['current_password']);
        $code = trim((string) $validated['code']);
        $valid = preg_match('/^\d{6}$/', $code) === 1
            && $twoFactor->verify($this->enabledSecret($admin), $code);

        if (! $valid && preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/i', $code) === 1) {
            $valid = $twoFactor->consumeRecoveryCode($admin, $code);
        }

        if (! $valid) {
            throw ValidationException::withMessages([
                'code' => __('The authentication code is invalid or expired.'),
            ]);
        }

        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'session_version' => $admin->session_version + 1,
            'remember_token' => Str::random(60),
        ])->save();
        $request->session()->regenerate();
        $request->session()->put('admin_session_version', $admin->session_version);

        $auditLogger->success(
            category: 'admin',
            action: 'auth.2fa_disabled',
            actor: $admin,
            target: $admin,
        );

        return redirect()->route('admin.account.security')->with('status', __('Two-factor authentication was disabled.'));
    }

    /** @return array{secret: string, expires_at: int}|null */
    private function setup(Request $request): ?array
    {
        $setup = $request->session()->get(self::SETUP_SESSION_KEY);

        if (! is_array($setup)
            || ! isset($setup['secret'], $setup['expires_at'])
            || ! is_string($setup['secret'])
            || ! is_int($setup['expires_at'])
            || $setup['expires_at'] < now()->timestamp
        ) {
            $request->session()->forget(self::SETUP_SESSION_KEY);

            return null;
        }

        try {
            $secret = Crypt::decryptString($setup['secret']);
        } catch (DecryptException) {
            $request->session()->forget(self::SETUP_SESSION_KEY);

            return null;
        }

        return [
            'secret' => $secret,
            'expires_at' => $setup['expires_at'],
        ];
    }

    /** @param list<string> $codes */
    private function storeRecoveryCodes(Request $request, array $codes): void
    {
        $json = json_encode($codes, JSON_THROW_ON_ERROR);
        $request->session()->put('admin_two_factor_recovery_codes', Crypt::encryptString($json));
    }

    /** @return list<string>|null */
    private function pullRecoveryCodes(Request $request): ?array
    {
        $encrypted = $request->session()->pull('admin_two_factor_recovery_codes');

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $codes = [];

        foreach ($decoded as $code) {
            if (! is_string($code)) {
                return null;
            }

            $codes[] = $code;
        }

        return $codes;
    }

    private function ensureCurrentPassword(Admin $admin, string $password): void
    {
        if (! Hash::check($password, $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }
    }

    private function ensureEnabled(Admin $admin): void
    {
        if (! $admin->twoFactorEnabled()) {
            throw ValidationException::withMessages([
                'code' => __('Two-factor authentication is not enabled.'),
            ]);
        }
    }

    private function enabledSecret(Admin $admin): string
    {
        $this->ensureEnabled($admin);
        $secret = $admin->twoFactorSecret();

        if ($secret === null) {
            throw ValidationException::withMessages([
                'code' => __('Two-factor authentication data cannot be decrypted. Contact the server owner.'),
            ]);
        }

        return $secret;
    }
}
