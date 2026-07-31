<?php

namespace Tests\Feature;

use App\Services\Html\SafeHtmlSanitizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use LengthException;
use Tests\TestCase;

final class InfrastructureHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_html_limit_rejects_oversized_utf8_without_cutting_characters(): void
    {
        $sanitizer = app(SafeHtmlSanitizer::class);

        foreach (['Ж', '😀'] as $character) {
            try {
                $sanitizer->sanitize(
                    '<p>'.str_repeat($character, 100001).'</p>',
                    SafeHtmlSanitizer::PROFILE_PAGE,
                );
                $this->fail('Oversized UTF-8 HTML was accepted.');
            } catch (LengthException $exception) {
                $this->assertSame('Sanitized HTML exceeds the maximum allowed byte length.', $exception->getMessage());
            }
        }
    }

    public function test_expired_database_cache_cleanup_preserves_live_rows(): void
    {
        $now = now()->getTimestamp();
        DB::table('cache')->insert([
            ['key' => 'expired-cache', 'value' => 'expired', 'expiration' => $now - 10],
            ['key' => 'live-cache', 'value' => 'live', 'expiration' => $now + 3600],
        ]);
        DB::table('cache_locks')->insert([
            ['key' => 'expired-lock', 'owner' => 'expired', 'expiration' => $now - 10],
            ['key' => 'live-lock', 'owner' => 'live', 'expiration' => $now + 3600],
        ]);

        $this->artisan('kaevcms:cache-clean', ['--batch' => 1, '--dry-run' => true])
            ->assertSuccessful();
        $this->assertDatabaseHas('cache', ['key' => 'expired-cache']);
        $this->assertDatabaseHas('cache_locks', ['key' => 'expired-lock']);

        $this->artisan('kaevcms:cache-clean', ['--batch' => 1])
            ->assertSuccessful();

        $this->assertDatabaseMissing('cache', ['key' => 'expired-cache']);
        $this->assertDatabaseMissing('cache_locks', ['key' => 'expired-lock']);
        $this->assertDatabaseHas('cache', ['key' => 'live-cache']);
        $this->assertDatabaseHas('cache_locks', ['key' => 'live-lock']);
    }

    public function test_expired_cache_cleanup_uses_configured_connection_and_tables(): void
    {
        config([
            'database.connections.cache_cleanup_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'cache.stores.database.connection' => 'cache_cleanup_test',
            'cache.stores.database.table' => 'custom_cache',
            'cache.stores.database.lock_connection' => 'cache_cleanup_test',
            'cache.stores.database.lock_table' => 'custom_cache_locks',
        ]);
        DB::purge('cache_cleanup_test');
        $schema = Schema::connection('cache_cleanup_test');
        $schema->create('custom_cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        $schema->create('custom_cache_locks', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        $now = now()->getTimestamp();
        DB::table('cache')->insert([
            'key' => 'default-expired-row',
            'value' => 'keep',
            'expiration' => $now - 10,
        ]);
        DB::connection('cache_cleanup_test')->table('custom_cache')->insert([
            ['key' => 'custom-expired-cache', 'value' => 'expired', 'expiration' => $now - 10],
            ['key' => 'custom-live-cache', 'value' => 'live', 'expiration' => $now + 3600],
        ]);
        DB::connection('cache_cleanup_test')->table('custom_cache_locks')->insert([
            ['key' => 'custom-expired-lock', 'owner' => 'expired', 'expiration' => $now - 10],
            ['key' => 'custom-live-lock', 'owner' => 'live', 'expiration' => $now + 3600],
        ]);

        $this->artisan('kaevcms:cache-clean', ['--batch' => 1])
            ->assertSuccessful();

        $this->assertSame(0, DB::connection('cache_cleanup_test')->table('custom_cache')->where('key', 'custom-expired-cache')->count());
        $this->assertSame(0, DB::connection('cache_cleanup_test')->table('custom_cache_locks')->where('key', 'custom-expired-lock')->count());
        $this->assertSame(1, DB::connection('cache_cleanup_test')->table('custom_cache')->where('key', 'custom-live-cache')->count());
        $this->assertSame(1, DB::connection('cache_cleanup_test')->table('custom_cache_locks')->where('key', 'custom-live-lock')->count());
        $this->assertDatabaseHas('cache', ['key' => 'default-expired-row']);
    }

    public function test_cache_expiration_indexes_and_maintenance_schedule_are_installed(): void
    {
        $cacheIndexes = array_column(Schema::getIndexes('cache'), 'name');
        $lockIndexes = array_column(Schema::getIndexes('cache_locks'), 'name');
        $schedule = File::get(base_path('routes/console.php'));

        $this->assertContains('cache_expiration_index', $cacheIndexes);
        $this->assertContains('cache_locks_expiration_index', $lockIndexes);
        $this->assertStringContainsString('kaevcms:cache-clean --batch=2000', $schedule);
        $this->assertStringContainsString('kaevcms:news-media-clean --hours=24', $schedule);
        $this->assertStringContainsString('kaevcms:page-media-clean --hours=24', $schedule);
    }

    public function test_editor_source_and_bundle_check_sanitized_html_byte_length(): void
    {
        $source = File::get(resource_path('js/admin/content-editor.js'));
        $bundle = File::get(public_path('assets/admin/js/news-editor.js'));

        $this->assertStringContainsString('new TextEncoder().encode(value).length', $source);
        $this->assertStringContainsString('TextEncoder', $bundle);
        $this->assertMatchesRegularExpression(
            '/TextEncoder\(\)\.encode\([^)]*\)\.length>(?:2e5|200000)/',
            $bundle,
        );
    }
}
