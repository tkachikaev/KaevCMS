<?php

namespace App\Services\Account;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Services\AuditLogger;
use App\Services\Mail\MailDeliveryDispatcher;
use App\Services\MailSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountPasswordChanger
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly MailSettings $mailSettings,
        private readonly MailDeliveryDispatcher $mailDelivery,
    ) {}

    public function change(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('The new password must be different from the current password.'),
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'remember_token' => Str::random(60),
        ])->save();

        $this->auditLogger->success(
            category: 'user',
            action: 'user.password_changed',
            actor: $user,
            target: $user,
        );

        if (! $this->mailSettings->isReady()) {
            return;
        }

        try {
            $this->mailDelivery->send($user, new PasswordChangedNotification, 'password_changed');
            $this->auditLogger->success(
                category: 'mail',
                action: 'mail.password_changed_sent',
                actor: $user,
                target: $user->email,
            );
        } catch (Throwable $exception) {
            Log::warning('Unable to send password changed notification.', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);
            $this->auditLogger->failed(
                category: 'mail',
                action: 'mail.password_changed_failed',
                actor: $user,
                target: $user->email,
                details: ['exception_class' => $exception::class],
            );
        }
    }
}
