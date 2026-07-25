<?php

namespace Tests\Feature\Updates;

use App\Models\Admin;
use App\Models\SystemUpdate;
use App\Services\Releases\InstalledVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;
use ZipArchive;

class SystemUpdateAdminTest extends TestCase
{
    use RefreshDatabase;

    private string $installedVersionMarker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installedVersionMarker = storage_path(
            'framework/testing/installed-version-'.bin2hex(random_bytes(8)).'.json',
        );
        config(['cms.installed_version_marker' => $this->installedVersionMarker]);
        app(InstalledVersion::class)->mark(cms_version());
    }

    protected function tearDown(): void
    {
        if (isset($this->installedVersionMarker) && is_file($this->installedVersionMarker)) {
            @unlink($this->installedVersionMarker);
        }

        parent::tearDown();
    }

    public function test_owner_sees_the_update_entry_below_the_system_version(): void
    {
        $owner = Admin::factory()->create();

        $this->actingAs($owner, 'admin')
            ->get('/admin/settings/system')
            ->assertOk()
            ->assertSee('Управление обновлениями')
            ->assertSee('/admin/settings/system/updates', false);
    }

    public function test_non_owner_cannot_open_the_system_updater(): void
    {
        $administrator = Admin::factory()->administrator()->create();

        $this->actingAs($administrator, 'admin')
            ->get('/admin/settings/system/updates')
            ->assertForbidden();
    }

    public function test_installation_requires_the_current_owner_password(): void
    {
        $owner = Admin::factory()->create();
        $update = SystemUpdate::query()->create([
            'uuid' => '9e822c39-3ef0-4da8-99d8-4dd47dbcc102',
            'admin_id' => $owner->id,
            'package_id' => 'kaevcms-0.32.1',
            'from_version' => '0.32.0',
            'target_version' => '0.32.1',
            'status' => SystemUpdate::STATUS_STAGED,
            'installation_type' => 'standard',
            'package_path' => 'kaevcms/updates/packages/missing.zip',
            'file_count' => 1,
            'delete_count' => 0,
            'manifest' => ['schema' => 1],
        ]);

        $this->actingAs($owner, 'admin')
            ->from('/admin/settings/system/updates/'.$update->id)
            ->post('/admin/settings/system/updates/'.$update->id.'/apply', [
                'current_password' => 'incorrect-password',
                'confirmation' => '1',
            ])
            ->assertRedirect('/admin/settings/system/updates/'.$update->id)
            ->assertSessionHasErrors('current_password');

        $this->assertSame(SystemUpdate::STATUS_STAGED, $update->fresh()?->status);
    }

    public function test_preflight_failure_keeps_the_package_staged_for_retry(): void
    {
        $owner = Admin::factory()->create();
        $update = SystemUpdate::query()->create([
            'uuid' => '571bf58a-b37b-42f9-9e5c-9d7035ff891c',
            'admin_id' => $owner->id,
            'package_id' => 'kaevcms-0.32.1',
            'from_version' => '0.32.0',
            'target_version' => '0.32.1',
            'status' => SystemUpdate::STATUS_STAGED,
            'installation_type' => 'standard',
            'package_path' => 'kaevcms/updates/packages/missing.zip',
            'file_count' => 1,
            'delete_count' => 0,
            'manifest' => ['schema' => 1],
        ]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/settings/system/updates/'.$update->id)
            ->assertOk();

        $this->actingAs($owner, 'admin')
            ->post('/admin/settings/system/updates/'.$update->id.'/apply', [
                'current_password' => 'CorrectPassword123',
                'confirmation' => '1',
            ])
            ->assertRedirect('/admin/settings/system/updates/'.$update->id)
            ->assertSessionHasErrors('update');

        $this->assertSame(SystemUpdate::STATUS_STAGED, $update->fresh()?->status);
    }

    public function test_owner_sees_recovery_controls_for_an_interrupted_update(): void
    {
        $owner = Admin::factory()->create();
        $update = SystemUpdate::query()->create([
            'uuid' => '4ec75be8-2844-4c65-b3bf-4c95f065ec29',
            'admin_id' => $owner->id,
            'package_id' => 'kaevcms-0.32.1',
            'from_version' => '0.32.0',
            'target_version' => '0.32.1',
            'status' => SystemUpdate::STATUS_APPLYING,
            'installation_type' => 'standard',
            'package_path' => 'kaevcms/updates/packages/interrupted.zip',
            'backup_path' => 'kaevcms/update-backups/4ec75be8-2844-4c65-b3bf-4c95f065ec29',
            'file_count' => 1,
            'delete_count' => 0,
            'manifest' => ['schema' => 1],
            'started_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/settings/system/updates/'.$update->id)
            ->assertOk()
            ->assertSee('Восстановление прерванного обновления')
            ->assertSee('/admin/settings/system/updates/'.$update->id.'/recover', false);
    }

    public function test_recovery_requires_the_current_owner_password(): void
    {
        $owner = Admin::factory()->create();
        $update = SystemUpdate::query()->create([
            'uuid' => 'a894f476-f055-46c5-8b6a-6249c56305f1',
            'admin_id' => $owner->id,
            'package_id' => 'kaevcms-0.32.1',
            'from_version' => '0.32.0',
            'target_version' => '0.32.1',
            'status' => SystemUpdate::STATUS_APPLYING,
            'installation_type' => 'standard',
            'package_path' => 'kaevcms/updates/packages/interrupted.zip',
            'backup_path' => 'kaevcms/update-backups/a894f476-f055-46c5-8b6a-6249c56305f1',
            'file_count' => 1,
            'delete_count' => 0,
            'manifest' => ['schema' => 1],
        ]);

        $this->actingAs($owner, 'admin')
            ->from('/admin/settings/system/updates/'.$update->id)
            ->post('/admin/settings/system/updates/'.$update->id.'/recover', [
                'current_password' => 'incorrect-password',
                'confirmation' => '1',
            ])
            ->assertRedirect('/admin/settings/system/updates/'.$update->id)
            ->assertSessionHasErrors('current_password');

        $this->assertSame(SystemUpdate::STATUS_APPLYING, $update->fresh()?->status);
    }

    #[RequiresPhpExtension('zip')]
    public function test_owner_can_stage_a_compatible_cumulative_update_package(): void
    {
        $owner = Admin::factory()->create();
        $currentVersion = cms_version();
        [$major, $minor, $patch] = array_map('intval', explode('.', $currentVersion));
        $targetVersion = $major.'.'.$minor.'.'.($patch + 1);
        $archive = $this->createUpdatePackage(
            targetVersion: $targetVersion,
            minimumVersion: '0.31.12',
            maximumVersion: $currentVersion,
        );

        $response = $this->actingAs($owner, 'admin')
            ->post('/admin/settings/system/updates', [
                'package' => new UploadedFile($archive, 'KaevCMS-update-to-'.$targetVersion.'.zip', 'application/zip', null, true),
            ]);

        $update = SystemUpdate::query()->firstOrFail();
        $response->assertRedirect('/admin/settings/system/updates/'.$update->id);
        $this->assertSame($currentVersion, $update->from_version);
        $this->assertSame($targetVersion, $update->target_version);
        $this->assertSame(SystemUpdate::STATUS_STAGED, $update->status);
        $this->assertSame(2, $update->file_count);
        $this->assertFileExists(storage_path('app/'.$update->package_path));
    }

    #[RequiresPhpExtension('zip')]
    public function test_package_cannot_target_runtime_secrets(): void
    {
        $owner = Admin::factory()->create();
        $currentVersion = cms_version();
        $targetVersion = $this->nextPatchVersion($currentVersion);
        $archive = $this->createUpdatePackage(
            targetVersion: $targetVersion,
            minimumVersion: $currentVersion,
            maximumVersion: $currentVersion,
            extraTarget: 'core/.env',
        );

        $this->actingAs($owner, 'admin')
            ->from('/admin/settings/system/updates')
            ->post('/admin/settings/system/updates', [
                'package' => new UploadedFile($archive, 'unsafe.zip', 'application/zip', null, true),
            ])
            ->assertRedirect('/admin/settings/system/updates')
            ->assertSessionHasErrors('package');

        $this->assertDatabaseCount('system_updates', 0);
    }

    public function test_recovery_refuses_to_start_when_the_required_file_backup_is_missing(): void
    {
        $owner = Admin::factory()->create();
        $uuid = '9244ab7d-4a9b-47f0-9c2e-c84e451cb109';
        $update = SystemUpdate::query()->create([
            'uuid' => $uuid,
            'admin_id' => $owner->id,
            'package_id' => 'kaevcms-0.32.1',
            'from_version' => '0.32.0',
            'target_version' => '0.32.1',
            'status' => SystemUpdate::STATUS_APPLYING,
            'phase' => SystemUpdate::PHASE_FILES,
            'installation_type' => 'standard',
            'package_path' => 'kaevcms/updates/packages/interrupted.zip',
            'backup_path' => 'kaevcms/update-backups/'.$uuid,
            'file_count' => 1,
            'delete_count' => 0,
            'manifest' => ['schema' => 1, 'migrate' => true],
        ]);

        $this->actingAs($owner, 'admin')
            ->post('/admin/settings/system/updates/'.$update->id.'/recover', [
                'current_password' => 'CorrectPassword123',
                'confirmation' => '1',
            ])
            ->assertRedirect('/admin/settings/system/updates/'.$update->id)
            ->assertSessionHasErrors('update');

        $fresh = $update->fresh();
        $this->assertSame(SystemUpdate::STATUS_APPLYING, $fresh?->status);
        $this->assertStringContainsString('резервная копия файлов', (string) $fresh?->error_summary);
    }

    #[RequiresPhpExtension('zip')]
    public function test_changed_archive_is_rejected_before_installation_starts(): void
    {
        $owner = Admin::factory()->create();
        $currentVersion = cms_version();
        $targetVersion = $this->nextPatchVersion($currentVersion);
        $archive = $this->createUpdatePackage(
            targetVersion: $targetVersion,
            minimumVersion: $currentVersion,
            maximumVersion: $currentVersion,
        );

        $this->actingAs($owner, 'admin')
            ->post('/admin/settings/system/updates', [
                'package' => new UploadedFile($archive, 'KaevCMS-update-to-'.$targetVersion.'.zip', 'application/zip', null, true),
            ]);

        $update = SystemUpdate::query()->firstOrFail();
        $storedArchive = storage_path('app/'.$update->package_path);
        $this->assertNotFalse(file_put_contents($storedArchive, "changed-after-upload\n", FILE_APPEND));

        $this->actingAs($owner, 'admin')
            ->get('/admin/settings/system/updates/'.$update->id)
            ->assertOk();

        $this->actingAs($owner, 'admin')
            ->post('/admin/settings/system/updates/'.$update->id.'/apply', [
                'current_password' => 'CorrectPassword123',
                'confirmation' => '1',
            ])
            ->assertRedirect('/admin/settings/system/updates/'.$update->id)
            ->assertSessionHasErrors('update');

        $fresh = $update->fresh();
        $this->assertSame(SystemUpdate::STATUS_STAGED, $fresh?->status);
        $this->assertNull($fresh?->phase);
    }

    private function nextPatchVersion(string $version): string
    {
        [$major, $minor, $patch] = array_map('intval', explode('.', $version));

        return $major.'.'.$minor.'.'.($patch + 1);
    }

    private function createUpdatePackage(
        string $targetVersion,
        string $minimumVersion,
        string $maximumVersion,
        string $extraTarget = 'core/README.md',
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'kaevcms-update-test-');
        $this->assertIsString($path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $payloads = [
            'payload/core/VERSION' => [
                'target' => 'core/VERSION',
                'contents' => $targetVersion."\n",
            ],
            'payload/core/README.md' => [
                'target' => $extraTarget,
                'contents' => "KaevCMS test update\n",
            ],
        ];

        $files = [];
        foreach ($payloads as $source => $payload) {
            $this->assertTrue($zip->addFromString($source, $payload['contents']));
            $files[] = [
                'source' => $source,
                'target' => $payload['target'],
                'sha256' => hash('sha256', $payload['contents']),
                'size' => strlen($payload['contents']),
            ];
        }

        $manifest = [
            'schema' => 1,
            'package_id' => 'kaevcms-'.$targetVersion,
            'name' => 'KaevCMS '.$targetVersion,
            'target_version' => $targetVersion,
            'minimum_version' => $minimumVersion,
            'maximum_version' => $maximumVersion,
            'requires' => [
                'php' => '8.3.0',
                'extensions' => ['zip'],
            ],
            'migrate' => true,
            'files' => $files,
            'delete' => [],
            'changelog' => ['Test update'],
        ];

        $this->assertTrue($zip->addFromString(
            'kaevcms-update.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        ));
        $this->assertTrue($zip->close());

        return $path;
    }
}
