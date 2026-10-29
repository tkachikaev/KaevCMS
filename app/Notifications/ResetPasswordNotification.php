<?php

namespace App\Notifications;

use App\Services\Localization\LanguageManager;
use App\Services\MailTemplateSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function token(): string
    {
        return $this->token;
    }

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

        $url = route('localized.password.reset', [
            'locale' => $locale,
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $templates = app(MailTemplateSettings::class);

        $message = $templates->mailMessage(
            MailTemplateSettings::PASSWORD_RESET,
            $templates->userVariables($notifiable, [
                'reset_url' => $url,
                'expires_in' => $templates->translatedDuration($locale),
            ], $locale),
            $url,
            $locale,
        );

        $previousLocale = App::getLocale();
        App::setLocale($locale);
        try {
            $message->line(e(__('Resetting your password also disconnects all previously linked social sign-in methods.')));
        } finally {
            App::setLocale($previousLocale);
        }

        return $message;
    }
}
