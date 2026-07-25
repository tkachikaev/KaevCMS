<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');
        $administrator = $guard->user();

        if ($administrator === null) {
            return redirect()->guest(route('admin.login'));
        }

        if (! $administrator->is_active) {
            return $this->logoutAndRedirect($request, __('The administrator account was disabled.'));
        }

        $sessionVersion = $request->session()->get('admin_session_version');

        if ($sessionVersion === null && $guard->viaRemember()) {
            $sessionVersion = $administrator->session_version;
            $request->session()->put('admin_session_version', $sessionVersion);
        }

        if ($sessionVersion === null || (int) $sessionVersion !== $administrator->session_version) {
            return $this->logoutAndRedirect($request, __('The administrator session was revoked. Sign in again.'));
        }

        return $next($request);
    }

    private function logoutAndRedirect(Request $request, string $message): Response
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('admin.login')
            ->with('status', $message);
    }
}
