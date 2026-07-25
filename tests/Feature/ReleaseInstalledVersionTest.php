<?php

namespace Tests\Feature;

use App\Services\Releases\InstalledVersion;
use App\Support\KaevCMS;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReleaseInstalledVersionTest extends TestCase
{
    use RefreshDatabase;

    private string $markerPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markerPath = storage_path('framework/testing/installed-version-'.bin2hex(random_bytes(6)).'.json');
        config(['cms.installed_version_marker' => $this->markerPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->markerPath);

        parent::tearDown();
    }

    public function test_installed_version_is_recorded_in_marker_and_database(): void
    {
        $service = app(InstalledVersion::class);
        $service->mark('0.23.8');

        $this->assertSame('0.23.8', $service->current());
        $this->assertDatabaseHas('cms_settings', [
            'key' => InstalledVersion::SETTING_KEY,
            'value' => '0.23.8',
        ]);

        $marker = json_decode((string) file_get_contents($this->markerPath), true);
        if (! is_array($marker)) {
            $this->fail('Installed version marker must contain a JSON object.');
        }
        $this->assertSame('0.23.8', $marker['version'] ?? null);
    }

    public function test_mismatched_marker_and_database_fail_closed(): void
    {
        $service = app(InstalledVersion::class);
        $service->mark('0.23.8');

        file_put_contents($this->markerPath, json_encode(['version' => '0.23.7']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        $service->current();
    }

    public function test_release_command_refuses_to_record_a_version_other_than_the_extracted_release(): void
    {
        $this->artisan('kaevcms:release-version', ['--mark' => '0.23.7'])
            ->expectsOutputToContain('Refusing to record')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->markerPath);
        $this->assertDatabaseMissing('cms_settings', [
            'key' => InstalledVersion::SETTING_KEY,
        ]);
    }

    public function test_release_command_records_the_current_release(): void
    {
        $version = KaevCMS::version();

        $this->artisan('kaevcms:release-version', ['--mark' => $version])
            ->expectsOutputToContain("Installed version recorded: {$version}")
            ->assertSuccessful();

        $this->artisan('kaevcms:release-version')
            ->expectsOutput($version)
            ->assertSuccessful();
    }

    public function test_maintenance_status_command_reports_the_framework_state(): void
    {
        $this->assertSame('cache', config('app.maintenance.driver'));
        $this->assertSame('array', config('app.maintenance.store'));

        $application = app(Application::class);
        $maintenanceMode = $application->maintenanceMode();
        $this->assertFalse($maintenanceMode->active());

        $this->artisan('kaevcms:maintenance-status')
            ->expectsOutput('up')
            ->assertSuccessful();

        try {
            $maintenanceMode->activate(['status' => 503]);

            $this->artisan('kaevcms:maintenance-status')
                ->expectsOutput('down')
                ->assertSuccessful();
        } finally {
            $maintenanceMode->deactivate();
        }
    }
}
