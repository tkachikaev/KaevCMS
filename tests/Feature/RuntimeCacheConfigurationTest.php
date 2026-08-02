<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RuntimeCacheConfigurationTest extends TestCase
{
    public function test_rate_limiter_uses_database_cache_by_default(): void
    {
        $this->assertSame('database', config('cache.limiter'));
        $this->assertArrayHasKey('database', config('cache.stores'));
        $this->assertSame('cache_locks', config('cache.stores.database.lock_table'));

        $configuration = File::get(config_path('cache.php'));
        $this->assertStringContainsString("env('CACHE_STORE', 'file')", $configuration);
        $this->assertStringContainsString("env('CACHE_LIMITER', 'database')", $configuration);
    }

    public function test_browser_runner_uses_its_isolated_database_for_rate_limits_and_retries_windows_sqlite_cleanup(): void
    {
        $runner = File::get(base_path('tests/browser/run.mjs'));

        $this->assertStringContainsString("CACHE_STORE: 'array'", $runner);
        $this->assertStringContainsString("CACHE_LIMITER: 'database'", $runner);
        $this->assertStringContainsString("BROWSER_TEST_LOGIN_LIMIT: '1000'", $runner);
        $this->assertStringContainsString("SERVER_MONITOR_REFRESH_INTERVAL_SECONDS: '300'", $runner);
        $this->assertStringContainsString("'serve', '--no-reload'", $runner);
        $this->assertStringContainsString("const environmentPath = resolve(root, '.env')", $runner);
        $this->assertStringContainsString('temporaryEnvironmentCreated', $runner);
        $this->assertStringContainsString('removeFileWithRetry', $runner);
        $this->assertStringContainsString("['EBUSY', 'EACCES', 'EPERM']", $runner);
        $this->assertStringContainsString('await stopProcessTree(server)', $runner);
    }

    public function test_public_auth_limits_and_browser_override_are_environment_configurable(): void
    {
        $configuration = File::get(config_path('cms.php'));
        $provider = File::get(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringContainsString("env('PUBLIC_LOGIN_IP_PER_MINUTE', 10)", $configuration);
        $this->assertStringContainsString("env('PUBLIC_LOGIN_IDENTITY_PER_HOUR', 20)", $configuration);
        $this->assertStringContainsString("env('BROWSER_TEST_LOGIN_LIMIT', 0)", $configuration);
        $this->assertStringContainsString("config('app.env') === 'testing'", $provider);
        $this->assertStringContainsString("config('cms.testing.browser_login_limit', 0)", $provider);
    }

    public function test_environment_template_documents_separate_limiter_store(): void
    {
        $environment = File::get(base_path('.env.example'));

        $this->assertStringContainsString("CACHE_STORE=file\nCACHE_LIMITER=database", str_replace("\r\n", "\n", $environment));
    }
}
