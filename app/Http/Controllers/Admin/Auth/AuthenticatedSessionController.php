<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AdminLoginService;
use App\Services\AuditLogger;
use App\Services\SecuritySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    private const TWO_FACTOR_CHALLENGE_KEY = 'admin_two_factor_challenge';

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->session()->has(self::TWO_FACTOR_CHALLENGE_KEY)) {
            return redirect()->route('admin.two-factor.challenge');
        }

        return view('admin.auth.login');
    }

    public function store(
        Request $request,
        AuditLogger $auditLogger,
        SecuritySettings $securitySettings,
        AdminLoginService $loginService,
    ): RedirectResponse {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $email = Str::lower(trim((string) $validated['email']));
        $throttleKey = $this->throttleKey($email, $request->ip());
        $security = $securitySettings->values();
        $maxAttempts = $security['login_max_attempts'];
        $decaySeconds = $security['login_decay_seconds'];

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('Too many sign-in attempts. Try again in :seconds seconds.', ['seconds' => $seconds]),
            ]);
        }

        $admin = Admin::query()->where('email', $email)->first();
        $passwordIsValid = $admin !== null
            && Hash::check((string) $validated['password'], $admin->password);

        if ($admin === null || ! $passwordIsValid || ! $admin->is_active) {
            RateLimiter::hit($throttleKey, $decaySeconds);
            $reason = $admin !== null && ! $admin->is_active && $passwordIsValid
                ? 'inactive'
                : 'invalid_credentials';
            $loginService->failed($request, $email, $reason, $admin, $auditLogger);

            throw ValidationException::withMessages([
                'email' => __('Invalid email address or password.'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        if (config('hashing.rehash_on_login', true) && Hash::needsRehash($admin->password)) {
            $admin->forceFill([
                'password' => Hash::make((string) $validated['password']),
            ])->save();
        }

        $remember = (bool) ($validated['remember'] ?? false);

        if ($admin->twoFactorEnabled()) {
            $request->session()->regenerate();
            $request->session()->put(self::TWO_FACTOR_CHALLENGE_KEY, [
                'admin_id' => (int) $admin->getKey(),
                'email' => $admin->email,
                'remember' => $remember,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ]);

            return redirect()->route('admin.two-factor.challenge');
        }

        $loginService->complete($admin, $request, $remember, $auditLogger);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        if ($admin !== null) {
            $auditLogger->success(
                category: 'admin',
                action: 'auth.logout',
                actor: $admin,
                target: __('Control panel'),
            );
        }

        $locale = app()->getLocale();
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('admin_locale', $locale);

        return redirect()->route('admin.login')->with('status', __('You signed out of the control panel.'));
    }

    private function throttleKey(string $email, ?string $ip): string
    {
        return 'admin-login:'.hash('sha256', $email.'|'.($ip ?? 'unknown'));
    }
}
