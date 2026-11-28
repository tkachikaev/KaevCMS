<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, AuditLogger $auditLogger): RedirectResponse|View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // The signed middleware checks the original URL and expiry. Keep the
        // account binding, but let a player who opened another account's email
        // sign out and retry instead of displaying a generic authorization error.
        if (! hash_equals((string) $user->getKey(), (string) $request->route('id'))) {
            return view('theme::auth.verify-email', ['accountMismatch' => true]);
        }

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash')), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
            $auditLogger->success(
                category: 'user',
                action: 'user.email_verified',
                actor: $user,
                target: $user->email,
            );
        }

        return redirect()
            ->to(public_route('account'))
            ->with('status', __('Email verified successfully.'));
    }
}
