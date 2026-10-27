<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Capture the login before logout/session changes as well as after login.
        $authenticated = $this->isAuthenticated();
        $response = $next($request);
        $headers = $response->headers;

        $authenticationRoute = collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(fn (string $middleware): bool => preg_match('/^(auth|guest)(:|$)/', $middleware) === 1);
        if ($authenticated || $this->isAuthenticated() || $authenticationRoute) {
            // Include module pages, JSON/Livewire responses and logout redirects.
            $headers->removeCacheControlDirective('public');
            $headers->removeCacheControlDirective('s-maxage');
            foreach (['no-store', 'no-cache', 'private', 'must-revalidate'] as $directive) {
                $headers->addCacheControlDirective($directive);
            }
            $headers->addCacheControlDirective('max-age', 0);
            $headers->set('Pragma', 'no-cache');
            $headers->remove('ETag');
            $headers->remove('Last-Modified');
        }

        if (! $headers->has('Content-Security-Policy')) {
            $formAction = "form-action 'self' https://yoomoney.ru";
            if ($request->routeIs('modules.donations.orders.checkout')) {
                $formAction .= ' https://pay.fk.money https://www.paypal.com';
            }
            // Bundled themes use external scripts and Livewire's CSP-safe build.
            // Inline CSS remains necessary for Livewire styles and dynamic UI sizing.
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' https: data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                $formAction,
                "frame-ancestors 'self'",
            ]));
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }

    /**
     * Authentication can change while downstream middleware/controllers run.
     *
     * @phpstan-impure
     */
    private function isAuthenticated(): bool
    {
        return Auth::guard('web')->check() || Auth::guard('admin')->check();
    }
}
