<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SocialRegistrationEmailVerification extends Notification
{
    public function __construct(private readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Verify your email to finish social registration'))
            ->line(__('Confirm this email address to finish signing up for your CMS account.'))
            ->action(__('Confirm email'), $this->url)
            ->line(__('The verification link expires in 15 minutes.'));
    }
}
