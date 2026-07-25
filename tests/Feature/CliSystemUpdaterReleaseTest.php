<?php

namespace Tests\Feature;

use App\Models\SystemUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CliSystemUpdaterReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_updater_rejects_a_missing_package_without_creating_state(): void
    {
        $this->artisan('kaevcms:update', [
            'package' => storage_path('app/missing-update.zip'),
            '--yes' => true,
        ])
            ->expectsOutput('The update package does not exist or is not a readable ZIP file.')
            ->assertFailed();

        $this->assertSame(0, SystemUpdate::query()->count());
    }

    public function test_release_contains_the_deployment_user_updater_command(): void
    {
        $source = file_get_contents(app_path('Console/Commands/InstallSystemUpdateCommand.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("protected \$signature = 'kaevcms:update", $source);
        $this->assertStringContainsString('SystemUpdateInstaller $installer', $source);
        $this->assertStringContainsString('{--yes', $source);
        $this->assertStringContainsString('chmod($path, 0600)', $source);
    }
}
