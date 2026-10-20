<?php

namespace Tests\Feature\Updates;

use App\Models\SystemUpdate;
use App\Services\Updates\VdsUpdateAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoverSystemUpdateCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $runtimeDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cms.updates.vds_agent_supported' => true,
            'cms.updates.vds_agent_recommended' => true,
        ]);

        $this->runtimeDirectory = storage_path('framework/testing/update-recover-'.bin2hex(random_bytes(8)));
        mkdir($this->runtimeDirectory.'/updates/packages', 0777, true);
        $this->app->instance(VdsUpdateAgent::class, new VdsUpdateAgent(
            markerPathOverride: $this->runtimeDirectory.'/agent.json',
            requestDirectoryOverride: $this->runtimeDirectory.'/requests',
            projectRootOverride: base_path(),
            packageDirectoryOverride: $this->runtimeDirectory.'/updates/packages',
            stagingDirectoryOverride: $this->runtimeDirectory.'/updates/staging',
        ));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->runtimeDirectory);
        foreach (glob(storage_path('app/kaevcms/updates/packages/*.zip')) ?: [] as $path) {
            if (str_contains((string) file_get_contents($path), 'recover-command-test')) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_nothing_to_recover_succeeds_without_changes(): void
    {
        $this->artisan('kaevcms:update-recover', ['--no-interaction' => true])
            ->expectsOutput('No interrupted or queued update was found.')
            ->assertSuccessful();
    }

    public function test_stuck_agent_request_is_cancelled_back_to_a_staged_package(): void
    {
        $agent = $this->app->make(VdsUpdateAgent::class);
        $agent->register([]);
        $update = $this->stagedUpdate();
        $agent->queue($update, Str::random(48));
        $this->assertCount(1, $agent->pendingRequests());

        $this->artisan('kaevcms:update-recover', ['--yes' => true])->assertSuccessful();

        $fresh = $update->fresh();
        $this->assertInstanceOf(SystemUpdate::class, $fresh);
        $this->assertTrue($fresh->isStaged());
        $this->assertFalse($fresh->isQueuedForAgent());
        $this->assertSame(SystemUpdate::EXECUTION_WEB, $fresh->execution_mode);
        $this->assertNull($fresh->maintenance_secret);
        $this->assertSame([], $agent->pendingRequests());
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.update_agent_request_cancelled']);
    }

    public function test_cancellation_requires_explicit_confirmation_when_not_interactive(): void
    {
        $agent = $this->app->make(VdsUpdateAgent::class);
        $agent->register([]);
        $update = $this->stagedUpdate();
        $agent->queue($update, Str::random(48));

        $this->artisan('kaevcms:update-recover', ['--no-interaction' => true])
            ->expectsOutput('Confirmation required: re-run with --yes.')
            ->assertFailed();

        $this->assertTrue($update->fresh()?->isQueuedForAgent());
        $this->assertCount(1, $agent->pendingRequests());
    }

    public function test_interrupted_update_without_a_valid_backup_fails_safely(): void
    {
        $update = $this->stagedUpdate();
        $update->forceFill([
            'status' => SystemUpdate::STATUS_APPLYING,
            'phase' => 'files',
            'backup_path' => 'kaevcms/update-backups/not-this-update',
        ])->save();

        $this->artisan('kaevcms:update-recover', ['uuid' => $update->uuid, '--yes' => true])
            ->expectsOutputToContain('The site stays in maintenance mode.')
            ->assertFailed();

        $this->assertSame(SystemUpdate::STATUS_APPLYING, $update->fresh()?->status);
    }

    public function test_several_stuck_updates_require_an_identifier(): void
    {
        foreach ([1, 2] as $index) {
            $this->stagedUpdate("99.0.{$index}")->forceFill(['status' => SystemUpdate::STATUS_APPLYING])->save();
        }

        $this->artisan('kaevcms:update-recover', ['--yes' => true])
            ->expectsOutput('Several updates need attention. Pass the identifier of the one to recover.')
            ->assertFailed();
    }

    public function test_unknown_identifier_is_rejected(): void
    {
        $this->artisan('kaevcms:update-recover', ['uuid' => (string) Str::uuid(), '--yes' => true])
            ->expectsOutput('No interrupted or queued update with this identifier was found.')
            ->assertFailed();
    }

    private function stagedUpdate(string $targetVersion = '99.0.0'): SystemUpdate
    {
        $uuid = (string) Str::uuid();
        $archivePath = storage_path('app/kaevcms/updates/packages/'.$uuid.'.zip');
        if (! is_dir(dirname($archivePath))) {
            mkdir(dirname($archivePath), 0777, true);
        }
        file_put_contents($archivePath, 'recover-command-test '.$uuid);

        return SystemUpdate::query()->create([
            'uuid' => $uuid,
            'package_id' => 'kaevcms-recover-command-test',
            'from_version' => cms_version(),
            'target_version' => $targetVersion,
            'status' => SystemUpdate::STATUS_STAGED,
            'installation_type' => 'standard',
            'package_path' => 'kaevcms/updates/packages/'.$uuid.'.zip',
            'package_sha256' => (string) hash_file('sha256', $archivePath),
            'file_count' => 1,
            'delete_count' => 0,
            'manifest' => ['schema' => 1],
        ]);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            is_dir($child) && ! is_link($child) ? $this->removeDirectory($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
