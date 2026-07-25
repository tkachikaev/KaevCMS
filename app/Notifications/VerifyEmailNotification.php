<?php

namespace App\Notifications;

use App\Services\Localization\LanguageManager;
use App\Services\MailTemplateSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $languages = app(LanguageManager::class);
        $locale = $languages->normalizeCode((string) ($notifiable->locale ?? '')) ?? $languages->default();
        if (! $languages->isEnabled($locale)) {
            $locale = $languages->default();
        }

        $url = URL::temporarySignedRoute(
            'localized.verification.verify',
            now()->addMinutes(60),
            [
                'locale' => $locale,
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        $templates = app(MailTemplateSettings::class);

        return $templates->mailMessage(
            MailTemplateSettings::EMAIL_VERIFICATION,
            $templates->userVariables($notifiable, [
                'verification_url' => $url,
                'expires_in' => $templates->translatedDuration($locale),
            ], $locale),
            $url,
            $locale,
        );
    }
}
