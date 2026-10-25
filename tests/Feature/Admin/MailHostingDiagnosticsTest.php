<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Services\Infrastructure\RuntimeDiagnostics;
use App\Services\Mail\MailHostingDiagnostics;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MailHostingDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_builds_the_cron_line_from_the_real_artisan_location(): void
    {
        $report = app(MailHostingDiagnostics::class)->report();

        $this->assertSame(base_path('artisan'), $report['artisan_path']);
        $this->assertTrue($report['artisan_readable']);
        $this->assertStringStartsWith('* * * * * cd ', $report['cron_command']);
        $this->assertStringContainsString(' artisan schedule:run >> /dev/null 2>&1', $report['cron_command']);
        $this->assertStringContainsString(base_path('artisan').' schedule:run', str_replace(["'", '"'], '', $report['cron_command_panel']));
        $this->assertStringNotContainsString('7.4', $report['cron_command']);
    }

    public function test_detected_php_command_line_binary_is_modern_enough_to_run_the_cms(): void
    {
        $report = app(MailHostingDiagnostics::class)->report();

        if ($report['php_cli'] === null) {
            $this->markTestSkipped('No PHP command-line binary could be verified in this environment.');
        }

        $this->assertTrue(version_compare((string) $report['php_cli_version'], '8.3.0', '>='));
        $this->assertContains($report['php_cli'], array_column($report['php_cli_candidates'], 'binary'));
    }

    public function test_delivery_page_shows_the_cron_line_check_button_and_php_details(): void
    {
        $this->actingAs($this->createAdmin(), 'admin')
            ->get('/admin/settings/mail/delivery')
            ->assertOk()
            ->assertSee('Cron для очереди в базе данных')
            ->assertSee('schedule:run', false)
            ->assertSee(base_path('artisan'), false)
            ->assertSee('PHP для cron')
            ->assertSee('Проверить cron')
            ->assertSee('data-probe-mode="cron"', false)
            ->assertDontSee('onfocus=', false);
    }

    public function test_cron_check_passes_only_after_a_scheduler_run_that_follows_the_request(): void
    {
        $diagnostics = app(MailHostingDiagnostics::class);
        $runtime = app(RuntimeDiagnostics::class);
        $admin = $this->createAdmin();

        Carbon::setTestNow('2026-10-25 12:00:00');

        try {
            // An old heartbeat proves nothing: cron may have been switched off since.
            $runtime->recordSchedulerHeartbeat();
            Carbon::setTestNow('2026-10-25 12:05:00');
            $this->assertSame('not_tested', $diagnostics->cronCheck()['status']);

            $this->actingAs($admin, 'admin')->post('/admin/settings/mail/cron-check')->assertRedirect();
            $this->assertSame('pending', $diagnostics->cronCheck()['status']);

            $this->actingAs($admin, 'admin')
                ->getJson('/admin/settings/mail/cron-check/status')
                ->assertOk()
                ->assertJsonPath('status', 'pending');

            Carbon::setTestNow('2026-10-25 12:05:40');
            $runtime->recordSchedulerHeartbeat();
            $this->assertSame('passed', $diagnostics->cronCheck()['status']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cron_check_fails_when_no_scheduler_run_arrives_in_time(): void
    {
        $diagnostics = app(MailHostingDiagnostics::class);
        Carbon::setTestNow('2026-10-25 12:00:00');

        try {
            $diagnostics->beginCronCheck();
            Carbon::setTestNow('2026-10-25 12:01:30');
            $this->assertSame('pending', $diagnostics->cronCheck()['status']);

            Carbon::setTestNow('2026-10-25 12:02:30');
            $this->assertSame('failed', $diagnostics->cronCheck()['status']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_database_probe_failure_names_the_missing_cron_instead_of_a_generic_message(): void
    {
        $diagnostics = app(MailHostingDiagnostics::class);

        $never = $diagnostics->explainFailure(MailSettings::MODE_DATABASE, 'MailDeliveryProbeTimeout');
        $this->assertStringContainsString('ни разу не запускал планировщик', $never['reason']);
        $this->assertStringContainsString('Проверить cron', $never['solution']);

        app(RuntimeDiagnostics::class)->recordSchedulerHeartbeat();
        $working = $diagnostics->explainFailure(MailSettings::MODE_DATABASE, 'MailDeliveryProbeTimeout');
        $this->assertStringContainsString('Cron работает', $working['reason']);

        Carbon::setTestNow(now()->addMinutes(20));
        try {
            $stopped = $diagnostics->explainFailure(MailSettings::MODE_DATABASE, 'MailDeliveryProbeTimeout');
            $this->assertStringContainsString('затем остановился', $stopped['reason']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_background_probe_timeout_explains_the_cause_and_offers_the_database_queue(): void
    {
        $explanation = app(MailHostingDiagnostics::class)->explainFailure(MailSettings::MODE_BACKGROUND, 'MailDeliveryProbeTimeout');

        $this->assertNotSame('', $explanation['reason']);
        $this->assertStringNotContainsString('MailDeliveryProbeTimeout', $explanation['reason']);
        $this->assertStringContainsString('Синхронная отправка продолжает работать', $explanation['solution']);
    }

    public function test_failed_background_check_shows_why_and_what_to_do_on_the_delivery_page(): void
    {
        $settings = app(MailSettings::class);
        $token = $settings->beginProbe(MailSettings::MODE_BACKGROUND);
        $settings->failProbe(MailSettings::MODE_BACKGROUND, $token, 'MailDeliveryProbeTimeout');

        $this->actingAs($this->createAdmin(), 'admin')
            ->get('/admin/settings/mail/delivery')
            ->assertOk()
            ->assertSee('Почему')
            ->assertSee('Что делать')
            ->assertSee('Синхронная отправка продолжает работать');
    }

    public function test_cron_line_quotes_paths_with_spaces(): void
    {
        $report = app(MailHostingDiagnostics::class)->report();

        // The working directory is always escaped, so a path with spaces cannot split the command.
        $this->assertStringContainsString(escapeshellarg(base_path()), $report['cron_command']);
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
}
