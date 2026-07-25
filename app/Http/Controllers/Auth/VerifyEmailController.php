<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $wasVerified = $request->user()->hasVerifiedEmail();

        if (! $wasVerified) {
            $request->fulfill();
            $auditLogger->success(
                category: 'user',
                action: 'user.email_verified',
                actor: $request->user(),
                target: $request->user()->email,
            );
        }

        return redirect()
            ->to(public_route('account'))
            ->with('status', __('Email verified successfully.'));
    }
}
