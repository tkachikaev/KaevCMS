<?php

namespace Tests\Feature\Updates;

use App\Models\Admin;
use App\Models\SystemUpdate;
use App\Services\Updates\VdsUpdateAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class VdsUpdateAgentTest extends TestCase
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

        $this->runtimeDirectory = storage_path('framework/testing/vds-agent-'.bin2hex(random_bytes(8)));
        mkdir($this->runtimeDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->runtimeDirectory);

        parent::tearDown();
    }

    public function test_agent_registration_is_bound_to_the_current_project_and_request_directory(): void
    {
        $agent = $this->agent();

        $this->assertSame('missing', $agent->status()->state);

        $agent->register([
            'service_name' => 'kaevcms-update-agent-test.service',
            'path_unit' => 'kaevcms-update-agent-test.path',
            'php_binary' => '/usr/bin/php8.3',
        ]);

        $status = $agent->status();
        $this->assertTrue($status->isReady());
        $this->assertSame(1, $status->metadata['agent_version']);
        $this->assertSame(realpath(base_path()), $status->metadata['project_root']);
        $this->assertDirectoryExists($agent->requestDirectory());

        $agent->unregister();
        $this->assertSame('missing', $agent->status()->state);
    }

    public function test_verified_update_is_queued_with_an_encrypted_secret_and_atomic_request_file(): void
    {
        $agent = $this->agent();
        $agent->register([]);

        $owner = Admin::factory()->create();
        $uuid = (string) Str::uuid();
        $archivePath = storage_path('app/kaevcms/updates/packages/'.$uuid.'.zip');
        if (! is_dir(dirname($archivePath))) {
            mkdir(dirname($archivePath), 0777, true);
        }
        file_put_contents($archivePath, 'verified update archive');
        $sha256 = hash_file('sha256', $archivePath);
        $this->assertIsString($sha256);

        $update = SystemUpdate::query()->create([
            'uuid' => $uuid,
            'admin_id' => $owner->id,
            'package_id' => 'kaevcms-agent-test',
            'from_version' => cms_version(),
            'target_version' => '99.0.0',
            'status' => SystemUpdate::STATUS_STAGED,
            'installation_type' => 'standard',
            'package_path' => 'kaevcms/updates/packages/'.$uuid.'.zip',
            'package_sha256' => $sha256,
            'file_count' => 1,
            'delete_count' => 0,
            'manifest' => ['schema' => 1],
        ]);

        $secret = Str::random(48);
        $queued = $agent->queue($update, $secret)->fresh();

        $this->assertInstanceOf(SystemUpdate::class, $queued);
        $this->assertTrue($queued->isQueuedForAgent());
        $this->assertSame(SystemUpdate::EXECUTION_VDS_AGENT, $queued->execution_mode);
        $this->assertSame($secret, $queued->maintenance_secret);
        $this->assertNotSame(
            $secret,
            DB::table('system_updates')->where('id', $update->id)->value('maintenance_secret'),
        );

        $requests = $agent->pendingRequests();
        $this->assertCount(1, $requests);
        $this->assertSame([
            'uuid' => $uuid,
            'package_sha256' => strtolower($sha256),
        ], $agent->readRequest($requests[0]));
        $this->assertSame([], glob($agent->requestDirectory().'/*.tmp') ?: []);

        @unlink($archivePath);
    }

    public function test_agent_rejects_a_package_changed_after_verification(): void
    {
        $agent = $this->agent();
        $agent->register([]);

        $uuid = (string) Str::uuid();
        $archivePath = storage_path('app/kaevcms/updates/packages/'.$uuid.'.zip');
        if (! is_dir(dirname($archivePath))) {
            mkdir(dirname($archivePath), 0777, true);
        }
        file_put_contents($archivePath, 'changed package');

        $update = SystemUpdate::query()->create([
            'uuid' => $uuid,
            'package_id' => 'kaevcms-agent-changed',
            'from_version' => cms_version(),
            'target_version' => '99.0.1',
            'status' => SystemUpdate::STATUS_STAGED,
            'installation_type' => 'standard',
            'package_path' => 'kaevcms/updates/packages/'.$uuid.'.zip',
            'package_sha256' => str_repeat('0', 64),
            'file_count' => 1,
            'delete_count' => 0,
            'manifest' => ['schema' => 1],
        ]);

        $this->expectException(RuntimeException::class);

        try {
            $agent->queue($update, Str::random(48));
        } finally {
            $this->assertFalse($update->fresh()?->isQueuedForAgent());
            $this->assertSame([], $agent->pendingRequests());
            @unlink($archivePath);
        }
    }

    private function agent(): VdsUpdateAgent
    {
        return new VdsUpdateAgent(
            markerPathOverride: $this->runtimeDirectory.'/agent.json',
            requestDirectoryOverride: $this->runtimeDirectory.'/requests',
            projectRootOverride: base_path(),
        );
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
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
