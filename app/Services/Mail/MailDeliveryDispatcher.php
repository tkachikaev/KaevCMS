<?php

namespace App\Services\Mail;

use App\Jobs\Mail\SendUserMailNotification;
use App\Models\User;
use App\Services\MailSettings;
use Illuminate\Notifications\Notification;
use Throwable;

final class MailDeliveryDispatcher
{
    public function __construct(
        private readonly MailSettings $settings,
        private readonly MailDeliveryMonitor $monitor,
    ) {}

    public function send(User $user, Notification $notification, string $type): void
    {
        $mode = $this->settings->deliveryMode();
        $deliveryId = $this->monitor->start(
            userId: (int) $user->getKey(),
            type: $type,
            recipient: (string) $user->email,
            mode: $mode,
        );
        $job = new SendUserMailNotification($user, $notification, $deliveryId);

        if (in_array($mode, [MailSettings::MODE_BACKGROUND, MailSettings::MODE_DATABASE], true)) {
            try {
                dispatch($job)->onConnection($mode)->onQueue('mail');
            } catch (Throwable $exception) {
                $this->monitor->markFailed($deliveryId, $exception);

                throw $exception;
            }

            return;
        }

        try {
            app()->call([$job, 'handle']);
        } catch (Throwable $exception) {
            $this->monitor->markFailed($deliveryId, $exception);

            throw $exception;
        }
    }
}
