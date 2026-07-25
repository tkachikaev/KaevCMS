<?php

namespace Tests\Feature\Admin;

use App\Jobs\Mail\MailDeliveryModeProbe;
use App\Jobs\Mail\SendUserMailNotification;
use App\Models\Admin;
use App\Models\MailDelivery;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\CmsSettings;
use App\Services\Mail\MailDeliveryMonitor;
use App\Services\MailSettings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\BackgroundQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailDeliveryModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_tab_shows_three_modes_and_connection_tab_stays_focused_on_smtp(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/mail/delivery')
            ->assertOk()
            ->assertSee('Режим отправки')
            ->assertSee('Синхронный')
            ->assertSee('Асинхронный')
            ->assertSee('Асинхронный с очередью в базе данных')
            ->assertSee('Чем отличаются режимы')
            ->assertSee('value="background"', false)
            ->assertSee('value="database"', false);

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/mail')
            ->assertOk()
            ->assertSee('Подключение SMTP')
            ->assertDontSee('Режим отправки');

        $this->assertSame(MailSettings::MODE_SYNC, app(MailSettings::class)->deliveryMode());
    }

    public function test_linux_vds_mail_queue_documentation_covers_supervisor_and_cron(): void
    {
        $documentation = (string) file_get_contents(base_path('docs/MAIL.md'));

        $this->assertStringContainsString('Настройка асинхронной очереди на Linux VDS', $documentation);
        $this->assertStringContainsString('/etc/supervisor/conf.d/kaevcms-mail.conf', $documentation);
        $this->assertStringContainsString('kaevcms:queue-drain', $documentation);
        $this->assertStringContainsString('mail-probe,mail,default', $documentation);
        $this->assertStringContainsString('php artisan queue:restart', $documentation);
        $this->assertStringContainsString('php artisan schedule:run', $documentation);
    }

    public function test_laravel_background_and_database_connections_resolve_real_queue_drivers(): void
    {
        $background = app('queue')->connection('background');
        $database = app('queue')->connection('database');

        $this->assertSame('background', config('queue.connections.background.driver'));
        $this->assertSame('database', config('queue.connections.database.driver'));
        $this->assertInstanceOf(BackgroundQueue::class, $background);
        $this->assertInstanceOf(DatabaseQueue::class, $database);
    }

    public function test_selecting_untested_asynchronous_mode_starts_probe_without_enabling_it(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->from('/admin/settings/mail/delivery')
            ->put('/admin/settings/mail/delivery-mode', [
                'delivery_mode' => MailSettings::MODE_BACKGROUND,
            ])
            ->assertRedirect('/admin/settings/mail/delivery')
            ->assertSessionHas('status');

        Queue::assertPushed(MailDeliveryModeProbe::class, function (MailDeliveryModeProbe $job): bool {
            return $job->mode === MailSettings::MODE_BACKGROUND
                && $job->connection === 'background'
                && $job->queue === 'mail-probe'
                && $job->token !== '';
        });

        $values = app(MailSettings::class)->values();
        $this->assertSame(MailSettings::MODE_SYNC, $values['delivery_mode']);
        $this->assertSame('pending', $values['background_probe_status']);
    }

    public function test_selecting_untested_database_mode_starts_database_probe_without_enabling_it(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->from('/admin/settings/mail/delivery')
            ->put('/admin/settings/mail/delivery-mode', [
                'delivery_mode' => MailSettings::MODE_DATABASE,
            ])
            ->assertRedirect('/admin/settings/mail/delivery')
            ->assertSessionHas('status');

        Queue::assertPushed(MailDeliveryModeProbe::class, function (MailDeliveryModeProbe $job): bool {
            return $job->mode === MailSettings::MODE_DATABASE
                && $job->connection === 'database'
                && $job->queue === 'mail-probe'
                && $job->token !== '';
        });

        $values = app(MailSettings::class)->values();
        $this->assertSame(MailSettings::MODE_SYNC, $values['delivery_mode']);
        $this->assertSame('pending', $values['database_probe_status']);
    }

    public function test_successful_asynchronous_probe_enables_requested_mode_automatically(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();
        $mailSettings = app(MailSettings::class);

        $this->actingAs($admin, 'admin')->put('/admin/settings/mail/delivery-mode', [
            'delivery_mode' => MailSettings::MODE_BACKGROUND,
        ]);

        $token = (string) app(CmsSettings::class)->get(MailSettings::KEY_BACKGROUND_PROBE_TOKEN, '');
        (new MailDeliveryModeProbe(MailSettings::MODE_BACKGROUND, $token))->handle($mailSettings);

        $this->assertSame(MailSettings::MODE_BACKGROUND, $mailSettings->deliveryMode());
        $this->assertTrue($mailSettings->backgroundSupported());
    }

    public function test_successful_database_probe_enables_requested_mode_automatically(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();
        $mailSettings = app(MailSettings::class);

        $this->actingAs($admin, 'admin')->put('/admin/settings/mail/delivery-mode', [
            'delivery_mode' => MailSettings::MODE_DATABASE,
        ]);

        $token = (string) app(CmsSettings::class)->get(MailSettings::KEY_DATABASE_PROBE_TOKEN, '');
        (new MailDeliveryModeProbe(MailSettings::MODE_DATABASE, $token))->handle($mailSettings);

        $this->assertSame(MailSettings::MODE_DATABASE, $mailSettings->deliveryMode());
        $this->assertTrue($mailSettings->databaseSupported());
    }

    public function test_only_the_most_recent_requested_mode_can_activate_after_parallel_checks(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();
        $mailSettings = app(MailSettings::class);

        $this->actingAs($admin, 'admin')->put('/admin/settings/mail/delivery-mode', [
            'delivery_mode' => MailSettings::MODE_BACKGROUND,
        ]);
        $backgroundToken = (string) app(CmsSettings::class)->get(MailSettings::KEY_BACKGROUND_PROBE_TOKEN, '');

        $this->actingAs($admin, 'admin')->put('/admin/settings/mail/delivery-mode', [
            'delivery_mode' => MailSettings::MODE_DATABASE,
        ]);
        $databaseToken = (string) app(CmsSettings::class)->get(MailSettings::KEY_DATABASE_PROBE_TOKEN, '');

        (new MailDeliveryModeProbe(MailSettings::MODE_BACKGROUND, $backgroundToken))->handle($mailSettings);
        $this->assertSame(MailSettings::MODE_SYNC, $mailSettings->deliveryMode());

        (new MailDeliveryModeProbe(MailSettings::MODE_DATABASE, $databaseToken))->handle($mailSettings);
        $this->assertSame(MailSettings::MODE_DATABASE, $mailSettings->deliveryMode());
    }

    public function test_switching_back_to_synchronous_cancels_pending_automatic_activation(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();
        $mailSettings = app(MailSettings::class);

        $this->actingAs($admin, 'admin')->put('/admin/settings/mail/delivery-mode', [
            'delivery_mode' => MailSettings::MODE_DATABASE,
        ]);
        $token = (string) app(CmsSettings::class)->get(MailSettings::KEY_DATABASE_PROBE_TOKEN, '');

        $this->actingAs($admin, 'admin')->put('/admin/settings/mail/delivery-mode', [
            'delivery_mode' => MailSettings::MODE_SYNC,
        ]);
        (new MailDeliveryModeProbe(MailSettings::MODE_DATABASE, $token))->handle($mailSettings);

        $this->assertSame(MailSettings::MODE_SYNC, $mailSettings->deliveryMode());
        $this->assertTrue($mailSettings->databaseSupported());
    }

    public function test_manual_mode_check_does_not_change_current_delivery_mode(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings/mail/delivery-probe', [
                'delivery_mode' => MailSettings::MODE_DATABASE,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $token = (string) app(CmsSettings::class)->get(MailSettings::KEY_DATABASE_PROBE_TOKEN, '');
        (new MailDeliveryModeProbe(MailSettings::MODE_DATABASE, $token))->handle(app(MailSettings::class));

        $this->assertTrue(app(MailSettings::class)->databaseSupported());
        $this->assertSame(MailSettings::MODE_SYNC, app(MailSettings::class)->deliveryMode());
    }

    public function test_timed_out_asynchronous_probe_keeps_or_restores_synchronous_mode(): void
    {
        $mailSettings = app(MailSettings::class);
        $token = $mailSettings->beginProbe(MailSettings::MODE_BACKGROUND);
        $mailSettings->completeProbe(MailSettings::MODE_BACKGROUND, $token);
        $mailSettings->setDeliveryMode(MailSettings::MODE_BACKGROUND);

        Carbon::setTestNow(now());

        try {
            $mailSettings->beginProbe(MailSettings::MODE_BACKGROUND, true);
            Carbon::setTestNow(now()->addSeconds(16));

            $values = $mailSettings->values();

            $this->assertSame('failed', $values['background_probe_status']);
            $this->assertSame(MailSettings::MODE_SYNC, $values['delivery_mode']);
            $this->assertFalse($values['background_supported']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_database_probe_allows_scheduler_time_before_it_fails(): void
    {
        $mailSettings = app(MailSettings::class);
        Carbon::setTestNow(now());

        try {
            $mailSettings->beginProbe(MailSettings::MODE_DATABASE, true);
            Carbon::setTestNow(now()->addSeconds(60));
            $this->assertSame('pending', $mailSettings->values()['database_probe_status']);

            Carbon::setTestNow(now()->addSeconds(31));
            $values = $mailSettings->values();
            $this->assertSame('failed', $values['database_probe_status']);
            $this->assertSame(MailSettings::MODE_SYNC, $values['delivery_mode']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_database_probe_cannot_revive_a_timed_out_check(): void
    {
        $mailSettings = app(MailSettings::class);
        Carbon::setTestNow(now());

        try {
            $token = $mailSettings->beginProbe(MailSettings::MODE_DATABASE, true);
            Carbon::setTestNow(now()->addSeconds(91));
            $this->assertSame('failed', $mailSettings->values()['database_probe_status']);

            (new MailDeliveryModeProbe(MailSettings::MODE_DATABASE, $token))->handle($mailSettings);

            $this->assertSame('failed', $mailSettings->values()['database_probe_status']);
            $this->assertSame(MailSettings::MODE_SYNC, $mailSettings->deliveryMode());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_scheduler_runs_dynamic_database_queue_drain_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'kaevcms:queue-drain'));

        $this->assertNotNull($event);
        $this->assertStringNotContainsString('--queue=mail-probe,mail', (string) $event->command);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_updating_smtp_settings_does_not_reset_database_delivery_mode(): void
    {
        $admin = $this->createAdmin();
        $mailSettings = app(MailSettings::class);
        $token = $mailSettings->beginProbe(MailSettings::MODE_DATABASE);
        $mailSettings->completeProbe(MailSettings::MODE_DATABASE, $token);
        $mailSettings->setDeliveryMode(MailSettings::MODE_DATABASE);

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/mail', [
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'encryption' => 'tls',
                'smtp_username' => '',
                'smtp_password' => '',
                'from_address' => 'no-reply@example.com',
                'from_name' => 'KaevCMS Test',
                'notification_email' => 'admin@example.com',
            ])
            ->assertRedirect(route('admin.settings.mail'));

        $this->assertSame(MailSettings::MODE_DATABASE, $mailSettings->deliveryMode());
        $this->assertTrue($mailSettings->databaseSupported());
    }

    public function test_synchronous_account_email_is_sent_and_recorded_immediately(): void
    {
        Notification::fake();
        $user = $this->createUser();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertDatabaseHas('mail_deliveries', [
            'user_id' => $user->id,
            'type' => 'email_verification',
            'recipient' => $user->email,
            'mode' => MailSettings::MODE_SYNC,
            'status' => MailDelivery::STATUS_SENT,
        ]);
    }

    public function test_asynchronous_account_email_is_dispatched_on_background_connection(): void
    {
        Queue::fake();
        $user = $this->createUser();
        $this->enableMode(MailSettings::MODE_BACKGROUND);

        $user->sendEmailVerificationNotification();

        Queue::assertPushed(SendUserMailNotification::class, function (SendUserMailNotification $job) use ($user): bool {
            return $job->user->is($user)
                && $job->connection === 'background'
                && $job->queue === 'mail'
                && $job->deliveryId !== null;
        });

        $this->assertDatabaseHas('mail_deliveries', [
            'user_id' => $user->id,
            'mode' => MailSettings::MODE_BACKGROUND,
            'status' => MailDelivery::STATUS_PENDING,
        ]);
    }

    public function test_database_account_email_is_persisted_on_database_queue(): void
    {
        Queue::fake();
        $user = $this->createUser();
        $this->enableMode(MailSettings::MODE_DATABASE);

        $user->sendEmailVerificationNotification();

        Queue::assertPushed(SendUserMailNotification::class, function (SendUserMailNotification $job) use ($user): bool {
            return $job->user->is($user)
                && $job->connection === 'database'
                && $job->queue === 'mail'
                && $job->deliveryId !== null;
        });

        $this->assertDatabaseHas('mail_deliveries', [
            'user_id' => $user->id,
            'mode' => MailSettings::MODE_DATABASE,
            'status' => MailDelivery::STATUS_PENDING,
        ]);
    }

    public function test_database_account_email_is_really_persisted_without_queue_fake(): void
    {
        $user = $this->createUser();
        $this->enableMode(MailSettings::MODE_DATABASE);

        $user->sendEmailVerificationNotification();

        $this->assertDatabaseHas('jobs', [
            'queue' => 'mail',
        ]);
        $this->assertDatabaseHas('mail_deliveries', [
            'user_id' => $user->id,
            'mode' => MailSettings::MODE_DATABASE,
            'status' => MailDelivery::STATUS_PENDING,
        ]);
    }

    public function test_mail_job_marks_delivery_as_sent_after_execution(): void
    {
        Notification::fake();
        $user = $this->createUser();
        $deliveryId = app(MailDeliveryMonitor::class)->start(
            userId: $user->id,
            type: 'email_verification',
            recipient: $user->email,
            mode: MailSettings::MODE_DATABASE,
        );
        $this->assertNotNull($deliveryId);
        $job = new SendUserMailNotification($user, new VerifyEmailNotification, $deliveryId);

        $job->handle(app(MailSettings::class), app(MailDeliveryMonitor::class));

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertDatabaseHas('mail_deliveries', [
            'id' => $deliveryId,
            'status' => MailDelivery::STATUS_SENT,
        ]);
    }

    public function test_sensitive_mail_job_payload_is_encrypted_in_database_and_failed_queue_storage(): void
    {
        $user = $this->createUser();
        $token = Password::broker('users')->createToken($user);
        $job = new SendUserMailNotification(
            $user,
            new ResetPasswordNotification($token),
            null,
        );

        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);

        dispatch($job)->onConnection('database')->onQueue('mail');

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsString($token, $payload);
        $this->assertStringNotContainsString($user->email, $payload);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'mail',
            'payload' => $payload,
            'exception' => 'test failure',
            'failed_at' => now(),
        ]);

        $failedPayload = (string) DB::table('failed_jobs')->value('payload');
        $this->assertStringNotContainsString($token, $failedPayload);
        $this->assertStringNotContainsString($user->email, $failedPayload);
    }

    public function test_mail_job_marks_delivery_failed_only_from_final_failed_callback(): void
    {
        $user = $this->createUser();
        $deliveryId = app(MailDeliveryMonitor::class)->start(
            userId: $user->id,
            type: 'password_reset',
            recipient: $user->email,
            mode: MailSettings::MODE_DATABASE,
        );
        $this->assertNotNull($deliveryId);
        $job = new SendUserMailNotification($user, new VerifyEmailNotification, $deliveryId);

        $this->assertDatabaseHas('mail_deliveries', [
            'id' => $deliveryId,
            'status' => MailDelivery::STATUS_PENDING,
        ]);

        $job->failed(new \RuntimeException('SMTP unavailable'));

        $this->assertDatabaseHas('mail_deliveries', [
            'id' => $deliveryId,
            'status' => MailDelivery::STATUS_FAILED,
            'error_class' => \RuntimeException::class,
        ]);
    }

    public function test_stale_queued_password_reset_email_is_skipped_before_smtp_delivery(): void
    {
        Notification::fake();
        $user = $this->createUser();
        $monitor = app(MailDeliveryMonitor::class);
        $staleToken = Password::broker('users')->createToken($user);
        $deliveryId = $monitor->start(
            userId: $user->id,
            type: 'password_reset',
            recipient: $user->email,
            mode: MailSettings::MODE_DATABASE,
        );
        $this->assertNotNull($deliveryId);
        $job = new SendUserMailNotification(
            $user,
            new ResetPasswordNotification($staleToken),
            $deliveryId,
        );

        $currentToken = Password::broker('users')->createToken($user);
        $job->handle(app(MailSettings::class), $monitor);

        Notification::assertNotSentTo($user, ResetPasswordNotification::class);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $currentToken));
        $this->assertDatabaseHas('mail_deliveries', [
            'id' => $deliveryId,
            'status' => MailDelivery::STATUS_SKIPPED,
            'error_class' => 'stale_password_reset_token',
        ]);
    }

    public function test_current_queued_password_reset_email_is_sent_without_invalidating_token(): void
    {
        Notification::fake();
        $user = $this->createUser();
        $monitor = app(MailDeliveryMonitor::class);
        $token = Password::broker('users')->createToken($user);
        $deliveryId = $monitor->start(
            userId: $user->id,
            type: 'password_reset',
            recipient: $user->email,
            mode: MailSettings::MODE_DATABASE,
        );
        $this->assertNotNull($deliveryId);
        $job = new SendUserMailNotification(
            $user,
            new ResetPasswordNotification($token),
            $deliveryId,
        );

        $job->handle(app(MailSettings::class), $monitor);

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            fn (ResetPasswordNotification $notification): bool => $notification->token() === $token,
        );
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $this->assertDatabaseHas('mail_deliveries', [
            'id' => $deliveryId,
            'status' => MailDelivery::STATUS_SENT,
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        $this->assertFalse(Password::broker('users')->tokenExists($user, $token));
    }

    public function test_delivery_settings_warn_when_app_url_differs_from_open_admin_address(): void
    {
        config(['app.url' => 'http://configured.example.test']);
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('http://current.example.test/admin/settings/mail/delivery')
            ->assertOk()
            ->assertSee('APP_URL не совпадает с адресом, открытым сейчас в браузере')
            ->assertSee('http://configured.example.test')
            ->assertSee('http://current.example.test');
    }

    public function test_dashboard_shows_database_mode_and_mail_delivery_health(): void
    {
        $admin = $this->createAdmin();
        $this->enableMode(MailSettings::MODE_DATABASE);
        MailDelivery::query()->create([
            'type' => 'email_verification',
            'recipient' => 'waiting@example.com',
            'mode' => MailSettings::MODE_DATABASE,
            'status' => MailDelivery::STATUS_PENDING,
            'queued_at' => now()->subMinutes(3),
        ]);
        MailDelivery::query()->create([
            'type' => 'password_reset',
            'recipient' => 'failed@example.com',
            'mode' => MailSettings::MODE_DATABASE,
            'status' => MailDelivery::STATUS_FAILED,
            'queued_at' => now()->subMinutes(4),
            'failed_at' => now()->subMinute(),
            'error_class' => 'RuntimeException',
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Отправка почты')
            ->assertSee('Асинхронный с очередью в базе данных')
            ->assertSee('Ожидание')
            ->assertSee('Ошибок за 7 дней')
            ->assertSee('Письмо ожидает отправки больше двух минут');
    }

    private function enableMode(string $mode): void
    {
        $mailSettings = app(MailSettings::class);
        $token = $mailSettings->beginProbe($mode);
        $mailSettings->completeProbe($mode, $token);
        $mailSettings->setDeliveryMode($mode);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'password' => Hash::make('Password123'),
        ]);
    }
}
