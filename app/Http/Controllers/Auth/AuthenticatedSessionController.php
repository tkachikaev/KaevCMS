<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('theme::auth.login');
    }

    public function store(LoginRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $login = Str::lower(trim((string) $request->validated()['login']));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) !== false ? 'email' : 'name';

        $guard = Auth::guard('web');

        if (! $guard->attempt([
            $field => $login,
            'password' => (string) $request->validated()['password'],
            'is_active' => true,
        ], $request->boolean('remember'))) {
            $auditLogger->failed(
                category: 'user',
                action: 'auth.login_failed',
                actor: $login,
                target: __('Account area'),
                actorType: 'user',
            );

            throw ValidationException::withMessages([
                'login' => __('Invalid username, email or password.'),
            ]);
        }

        $request->session()->regenerate();

        $user = $guard->user();
        $user?->forceFill([
            'last_login_at' => now(),
            'locale' => app()->getLocale(),
        ])->save();

        $auditLogger->success(
            category: 'user',
            action: 'auth.login',
            actor: $user,
            target: __('Account area'),
        );

        return redirect()->intended(public_route('account'));
    }

    public function destroy(AuditLogger $auditLogger): RedirectResponse
    {
        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user !== null) {
            $auditLogger->success(
                category: 'user',
                action: 'auth.logout',
                actor: $user,
                target: __('Account area'),
            );
        }

        $guard->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->to(public_route('home'));
    }
}
