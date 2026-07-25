<?php

namespace App\Jobs\Mail;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Mail\MailDeliveryMonitor;
use App\Services\MailSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Password;
use Throwable;

class SendUserMailNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly User $user,
        public readonly Notification $notification,
        public readonly ?int $deliveryId,
    ) {}

    public function handle(MailSettings $mailSettings, MailDeliveryMonitor $monitor): void
    {
        if ($this->notification instanceof ResetPasswordNotification
            && ! Password::broker('users')->tokenExists($this->user, $this->notification->token())) {
            $monitor->markSkipped($this->deliveryId, 'stale_password_reset_token');

            return;
        }

        $mailSettings->applyConfiguration();
        NotificationFacade::sendNow($this->user, $this->notification, ['mail']);
        $monitor->markSent($this->deliveryId);
    }

    public function failed(Throwable $exception): void
    {
        app(MailDeliveryMonitor::class)->markFailed($this->deliveryId, $exception);
    }
}
