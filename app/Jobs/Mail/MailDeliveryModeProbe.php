<?php

namespace App\Jobs\Mail;

use App\Services\MailSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MailDeliveryModeProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $mode,
        public readonly string $token,
    ) {}

    public function handle(MailSettings $mailSettings): void
    {
        $mailSettings->completeProbe($this->mode, $this->token);
    }

    public function failed(Throwable $exception): void
    {
        app(MailSettings::class)->failProbe($this->mode, $this->token, $exception::class);
    }
}
