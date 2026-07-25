<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectAuthenticatedAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');
        $administrator = $guard->user();

        if ($administrator === null) {
            return $next($request);
        }

        $sessionVersion = $request->session()->get('admin_session_version');

        if ($sessionVersion === null && $guard->viaRemember()) {
            $sessionVersion = $administrator->session_version;
            $request->session()->put('admin_session_version', $sessionVersion);
        }

        if (! $administrator->is_active
            || $sessionVersion === null
            || (int) $sessionVersion !== $administrator->session_version
        ) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $next($request);
        }

        return redirect()->route('admin.dashboard');
    }
}
