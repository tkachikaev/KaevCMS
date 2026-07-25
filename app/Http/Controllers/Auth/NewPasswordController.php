<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Rules\PasswordWithinHasherLimit;
use App\Services\AuditLogger;
use App\Services\Mail\MailDeliveryDispatcher;
use App\Services\MailSettings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Throwable;

class NewPasswordController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $token = (string) $request->route('token', '');
        $email = Str::lower(trim((string) $request->query('email', '')));
        $user = $email === '' ? null : User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! Password::broker('users')->tokenExists($user, $token)) {
            return redirect()
                ->to(public_route('password.request'))
                ->withErrors(['email' => __('The password reset link is invalid or has expired.')]);
        }

        return view('theme::auth.reset-password', [
            'token' => $token,
            'email' => $email,
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $auditLogger,
        MailSettings $mailSettings,
        MailDeliveryDispatcher $mailDelivery,
    ): RedirectResponse {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers(), new PasswordWithinHasherLimit],
        ], [
            'email.required' => __('Enter an email address.'),
            'email.email' => __('The email address is invalid.'),
            'password.required' => __('Enter a new password.'),
            'password.string' => __('The password must be a string.'),
            'password.min' => __('The password must be at least 8 characters.'),
            'password.letters' => __('The password must contain at least one letter.'),
            'password.numbers' => __('The password must contain at least one digit.'),
            'password.confirmed' => __('The passwords do not match.'),
        ]);

        $changedUser = null;
        $status = Password::reset(
            $validated,
            function (User $user, string $password) use ($auditLogger, &$changedUser): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $changedUser = $user;
                event(new PasswordReset($user));
                $auditLogger->success(
                    category: 'user',
                    action: 'user.password_changed',
                    actor: $user,
                    target: $user,
                );
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            Log::warning('Password reset request was rejected.', [
                'status' => $status,
            ]);

            return back()->withInput($request->only('email'))->withErrors([
                'email' => __('The password reset link is invalid or has expired.'),
            ]);
        }

        if ($changedUser instanceof User && $mailSettings->isReady()) {
            try {
                $mailDelivery->send($changedUser, new PasswordChangedNotification, 'password_changed');
                $auditLogger->success(
                    category: 'mail',
                    action: 'mail.password_changed_sent',
                    actor: $changedUser,
                    target: $changedUser->email,
                );
            } catch (Throwable $exception) {
                Log::warning('Unable to send password changed notification.', [
                    'user_id' => $changedUser->id,
                    'exception' => $exception::class,
                ]);
                $auditLogger->failed(
                    category: 'mail',
                    action: 'mail.password_changed_failed',
                    actor: $changedUser,
                    target: $changedUser->email,
                    details: ['exception_class' => $exception::class],
                );
            }
        }

        return redirect()
            ->to(public_route('login'))
            ->with('status', __('The password was changed. You can now sign in.'));
    }
}
