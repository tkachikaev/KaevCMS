<?php

namespace App\Notifications;

use App\Services\MailTemplateSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MailTemplateTestNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $templateKey,
        private readonly ?string $templateLocale = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $templates = app(MailTemplateSettings::class);
        $variables = $templates->demoVariables($this->templateKey, $this->templateLocale);
        $actionUrl = null;

        if ($this->templateKey === MailTemplateSettings::EMAIL_VERIFICATION) {
            $actionUrl = $variables['verification_url'];
        } elseif ($this->templateKey === MailTemplateSettings::PASSWORD_RESET) {
            $actionUrl = $variables['reset_url'];
        }

        return $templates->mailMessage($this->templateKey, $variables, $actionUrl, $this->templateLocale);
    }
}
