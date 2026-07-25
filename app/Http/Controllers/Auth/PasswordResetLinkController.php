<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('theme::auth.forgot-password');
    }

    public function store(Request $request, MailSettings $mailSettings, AuditLogger $auditLogger): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ], [
            'email.required' => __('Enter an email address.'),
            'email.email' => __('The email address is invalid.'),
        ]);

        $email = (string) $request->input('email');
        $auditLogger->success(
            category: 'user',
            action: 'user.password_reset_requested',
            actor: $email,
            target: __('Password reset'),
            actorType: 'user',
        );

        if (! $mailSettings->isReady()) {
            $auditLogger->failed(
                category: 'mail',
                action: 'mail.password_reset_failed',
                actor: $email,
                target: $email,
                details: ['reason' => 'mail_not_ready'],
                actorType: 'user',
            );

            return back()->withErrors([
                'email' => __('Password reset is temporarily unavailable. Contact the website administration.'),
            ]);
        }

        try {
            $status = Password::sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            Log::warning('Unable to send password reset link.', [
                'exception' => $exception::class,
            ]);
            $auditLogger->failed(
                category: 'mail',
                action: 'mail.password_reset_failed',
                actor: $email,
                target: $email,
                details: ['exception_class' => $exception::class],
                actorType: 'user',
            );

            return back()->withErrors([
                'email' => __('The email could not be sent. Try again later.'),
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors([
                'email' => __('A request was sent recently. Try again later.'),
            ]);
        }

        if ($status === Password::RESET_LINK_SENT) {
            $user = User::query()->where('email', $email)->first();
            $auditLogger->success(
                category: 'mail',
                action: 'mail.password_reset_sent',
                actor: $user ?? $email,
                target: $email,
                actorType: $user === null ? 'user' : null,
            );
        }

        return back()->with('status', __('If this email is registered, a password reset link has been sent to it.'));
    }
}
