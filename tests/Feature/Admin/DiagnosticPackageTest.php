<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\SystemUpdate;
use App\Services\Diagnostics\DiagnosticPackageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;
use ZipArchive;

#[RequiresPhpExtension('zip')]
class DiagnosticPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_contains_required_diagnostics_without_secrets_or_personal_data(): void
    {
        $logPath = storage_path('logs/laravel.log');
        $originalLog = File::exists($logPath) ? File::get($logPath) : null;
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, implode(PHP_EOL, [
            '[2026-08-01 01:30:00] production.ERROR: RuntimeException: Failed for Timur Person owner@example.com at 192.0.2.25 password=PlainPassword token=SecretToken APP_KEY=base64:SecretApplicationKey123456789=',
            '#0 '.base_path('app/Example.php').'(42): App\\Example->run()',
            '#1 {main}',
        ]).PHP_EOL);

        SystemUpdate::query()->create([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'package_id' => 'diagnostic-test',
            'from_version' => '0.44.34',
            'target_version' => '0.45.0',
            'status' => SystemUpdate::STATUS_FAILED,
            'phase' => SystemUpdate::PHASE_MIGRATIONS,
            'installation_type' => 'standard',
            'package_path' => 'kaevcms/updates/packages/test.zip',
            'file_count' => 12,
            'delete_count' => 1,
            'manifest' => [],
            'error_summary' => 'RuntimeException: owner@example.com token=UpdateSecret',
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\DiagnosticJob'], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: password=QueueSecret owner@example.com',
            'failed_at' => now(),
        ]);

        $package = null;

        try {
            $package = app(DiagnosticPackageBuilder::class)->build();
            $this->assertFileExists($package->path);
            $this->assertStringContainsString('KaevCMS-', $package->name);
            $this->assertStringContainsString('-diagnostics-', $package->name);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($package->path, ZipArchive::RDONLY));

            $entries = [];
            $contents = '';
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $this->assertIsString($name);
                $entries[] = $name;
                $entry = $zip->getFromIndex($index);
                $this->assertIsString($entry);
                $contents .= "\n".$entry;
            }
            $zip->close();

            $this->assertSame([
                'README.txt',
                'diagnostic-report.txt',
                'system.json',
                'modules.json',
                'migrations.json',
                'updates.json',
                'recent-errors.log',
            ], $entries);
            $this->assertStringContainsString('KaevCMS diagnostic package', $contents);
            $this->assertStringContainsString('RuntimeException', $contents);
            $this->assertStringContainsString('fingerprint=', $contents);
            $this->assertStringContainsString('pending_count', $contents);
            $this->assertStringContainsString('public_directory_present', $contents);

            foreach ([
                'PlainPassword',
                'SecretToken',
                'SecretApplicationKey',
                'UpdateSecret',
                'QueueSecret',
                'owner@example.com',
                '192.0.2.25',
                'Timur Person',
                base_path(),
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $contents);
            }
        } finally {
            if ($package !== null && is_file($package->path)) {
                @unlink($package->path);
            }

            if ($originalLog === null) {
                File::delete($logPath);
            } else {
                File::put($logPath, $originalLog);
            }
        }
    }

    public function test_owner_and_auditor_can_download_package_but_editor_cannot(): void
    {
        $owner = $this->admin('diagnostic-owner@example.com', AdminRole::Owner);
        $auditor = $this->admin('diagnostic-auditor@example.com', AdminRole::Auditor);
        $editor = $this->admin('diagnostic-editor@example.com', AdminRole::Editor);

        foreach ([$owner, $auditor] as $admin) {
            $response = $this->actingAs($admin, 'admin')
                ->get(route('admin.settings.system.diagnostics.download'))
                ->assertOk()
                ->assertHeader('content-type', 'application/zip')
                ->assertHeader('x-content-type-options', 'nosniff');

            $this->assertStringContainsString(
                'no-store',
                (string) $response->headers->get('cache-control'),
            );

            $this->assertStringContainsString(
                'attachment;',
                (string) $response->headers->get('content-disposition'),
            );
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'system.diagnostics_downloaded',
                'result' => 'success',
            ]);

            $file = $response->baseResponse->getFile()->getPathname();
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->actingAs($editor, 'admin')
            ->get(route('admin.settings.system.diagnostics.download'))
            ->assertForbidden();
    }

    private function admin(string $email, AdminRole $role): Admin
    {
        return Admin::query()->create([
            'name' => $role->value,
            'email' => $email,
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
            'role' => $role,
        ]);
    }
}
