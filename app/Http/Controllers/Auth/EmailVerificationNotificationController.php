<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    public function store(
        Request $request,
        RegistrationSettings $registrationSettings,
        MailSettings $mailSettings,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if (! $registrationSettings->emailVerificationRequired()) {
            return redirect()->to(public_route('account'));
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->to(public_route('account'));
        }

        if (! $mailSettings->isReady()) {
            return back()->withErrors([
                'email' => __('Email delivery is temporarily unavailable. Contact the website administration.'),
            ]);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            Log::warning('Unable to resend email verification notification.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);
            $auditLogger->failed(
                category: 'mail',
                action: 'mail.verification_failed',
                actor: $user,
                target: $user->email,
                details: ['exception_class' => $exception::class],
            );

            return back()->withErrors([
                'email' => __('The email could not be sent. Try again later.'),
            ]);
        }

        $auditLogger->success(
            category: 'mail',
            action: 'mail.verification_sent',
            actor: $user,
            target: $user->email,
            details: ['resend' => true],
        );

        return back()->with('status', __('A new verification link has been sent to your email.'));
    }
}
