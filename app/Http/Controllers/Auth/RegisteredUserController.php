<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class RegisteredUserController extends Controller
{
    public function create(RegistrationSettings $settings, MailSettings $mailSettings): View
    {
        if (! $settings->enabled()) {
            return view('theme::auth.registration-disabled', [
                'reason' => __('Website registration is temporarily disabled by the administration.'),
            ]);
        }

        if ($settings->emailVerificationRequired() && ! $mailSettings->isReady()) {
            return view('theme::auth.registration-disabled', [
                'reason' => __('Registration is temporarily unavailable because email delivery is not configured.'),
            ]);
        }

        return view('theme::auth.register', [
            'emailVerificationRequired' => $settings->emailVerificationRequired(),
        ]);
    }

    public function store(
        RegisterRequest $request,
        RegistrationSettings $settings,
        MailSettings $mailSettings,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        abort_unless($settings->enabled(), 403, __('New user registration is disabled.'));
        abort_if(
            $settings->emailVerificationRequired() && ! $mailSettings->isReady(),
            503,
            __('Registration is temporarily unavailable because email delivery is not configured.')
        );

        $validated = $request->validated();
        $user = User::query()->create([
            'name' => Str::lower(trim((string) $validated['name'])),
            'email' => Str::lower(trim((string) $validated['email'])),
            'password' => Hash::make((string) $validated['password']),
            'locale' => app()->getLocale(),
        ]);

        if (! $settings->emailVerificationRequired()) {
            $user->markEmailAsVerified();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $auditLogger->success(
            category: 'user',
            action: 'user.registered',
            actor: $user,
            target: $user,
            details: [
                'email_verification_required' => $settings->emailVerificationRequired(),
            ],
        );

        try {
            event(new Registered($user));
        } catch (Throwable $exception) {
            Log::warning('Unable to send registration email.', [
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

            return redirect()
                ->to(public_route('verification.notice'))
                ->with('warning', __('The account was created, but the verification email could not be sent. Try sending it again later.'));
        }

        if ($settings->emailVerificationRequired() && ! $user->hasVerifiedEmail()) {
            $auditLogger->success(
                category: 'mail',
                action: 'mail.verification_sent',
                actor: $user,
                target: $user->email,
            );

            return redirect()
                ->to(public_route('verification.notice'))
                ->with('status', __('The account was created. Check your inbox and verify your email.'));
        }

        return redirect()
            ->to(public_route('account'))
            ->with('status', __('The account was created successfully.'));
    }
}
