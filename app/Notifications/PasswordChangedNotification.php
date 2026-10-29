<?php

namespace App\Notifications;

use App\Services\MailTemplateSettings;
use Illuminate\Support\Facades\App;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly bool $socialLinksRevoked = false) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $templates = app(MailTemplateSettings::class);
        $locale = (string) ($notifiable->locale ?? app()->getLocale());

        $message = $templates->mailMessage(
            MailTemplateSettings::PASSWORD_CHANGED,
            $templates->userVariables($notifiable, [], $locale),
            null,
            $locale,
        );

        if ($this->socialLinksRevoked) {
            $previousLocale = App::getLocale();
            App::setLocale($locale);
            try {
                $message->line(e(__('After password recovery, previously connected social sign-in methods were disconnected. Connect them again in your account settings.')));
            } finally {
                App::setLocale($previousLocale);
            }
        }

        return $message;
    }
}
