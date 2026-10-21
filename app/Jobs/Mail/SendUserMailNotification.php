<?php

namespace App\Jobs\Mail;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Mail\MailDeliveryMonitor;
use App\Services\MailSettings;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class SendUserMailNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * While set, queued mail waits instead of trying the provider: one provider that hangs
     * costs every message several socket timeouts, which stalled the whole queue in the
     * load test (tests/load/RESULTS.md). Waiting does not count as a failed attempt.
     */
    public const PROVIDER_PAUSE_KEY = 'kaevcms:mail-provider-paused';

    public const PROVIDER_PAUSE_SECONDS = 60;

    /** Real delivery failures allowed within retryUntil(); pauses are not failures. */
    public int $maxExceptions = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 180, 300, 600];

    public function __construct(
        public readonly User $user,
        public readonly Notification $notification,
        public readonly ?int $deliveryId,
    ) {}

    /** A short provider outage is outlived without the administrator retrying by hand. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(MailSettings $mailSettings, MailDeliveryMonitor $monitor): void
    {
        if ($this->notification instanceof ResetPasswordNotification
            && ! Password::broker('users')->tokenExists($this->user, $this->notification->token())) {
            $monitor->markSkipped($this->deliveryId, 'stale_password_reset_token');

            return;
        }

        $queued = $this->queuedOnDatabase();
        if ($queued && Cache::has(self::PROVIDER_PAUSE_KEY)) {
            $this->release(self::PROVIDER_PAUSE_SECONDS);

            return;
        }

        $mailSettings->applyConfiguration();

        try {
            NotificationFacade::sendNow($this->user, $this->notification, ['mail']);
        } catch (TransportExceptionInterface $exception) {
            if ($queued) {
                Cache::put(self::PROVIDER_PAUSE_KEY, true, self::PROVIDER_PAUSE_SECONDS);
            }

            throw $exception;
        }

        $monitor->markSent($this->deliveryId);
    }

    /**
     * Only the database queue can put a message back. Synchronous delivery reports the error
     * to the request, and background delivery runs the job once in a child process.
     */
    private function queuedOnDatabase(): bool
    {
        return $this->job !== null && $this->job->getConnectionName() === 'database';
    }

    public function failed(Throwable $exception): void
    {
        app(MailDeliveryMonitor::class)->markFailed($this->deliveryId, $exception);
    }
}
