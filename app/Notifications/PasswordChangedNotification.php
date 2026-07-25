<?php

namespace App\Notifications;

use App\Services\MailTemplateSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $templates = app(MailTemplateSettings::class);
        $locale = (string) ($notifiable->locale ?? app()->getLocale());

        return $templates->mailMessage(
            MailTemplateSettings::PASSWORD_CHANGED,
            $templates->userVariables($notifiable, [], $locale),
            null,
            $locale,
        );
    }
}
