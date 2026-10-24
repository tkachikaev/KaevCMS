<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class AdminSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Livewire marks its own <style> blocks (loading states, navigation progress bar)
        // with this per-response nonce; other inline styles stay blocked.
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data: blob:; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'"
        );

        return $response;
    }
}
