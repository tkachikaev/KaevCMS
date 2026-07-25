<?php

namespace App\Http\Middleware;

use App\Services\RegistrationSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireConfiguredEmailVerification
{
    public function __construct(private readonly RegistrationSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($this->settings->emailVerificationRequired() && $user !== null && ! $user->hasVerifiedEmail()) {
            return redirect()->to(public_route('verification.notice'));
        }

        return $next($request);
    }
}
