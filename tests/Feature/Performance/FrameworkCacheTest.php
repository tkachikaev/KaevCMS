<?php

namespace Tests\Feature\Performance;

use App\Services\MailSettings;
use App\Support\FrameworkCache;
use App\Support\KaevCMS;
use App\Support\Modules\ModuleManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * Production sites build Laravel's caches by themselves (FrameworkCache); see tests/load/RESULTS.md.
 */
class FrameworkCacheTest extends TestCase
{
    private const MAIL_PASSWORD = 'cache-must-never-contain-this';

    public function test_caches_built_before_an_env_edit_or_for_another_version_are_discarded(): void
    {
        $files = new Filesystem;
        $base = storage_path('framework/testing/framework-cache-'.bin2hex(random_bytes(4)));
        $files->ensureDirectoryExists($base.'/bootstrap/cache');
        $original = $this->app;

        try {
            $app = new Application($base);
            $caches = [$app->getCachedConfigPath(), $app->getCachedRoutesPath(), $app->getCachedEventsPath()];
            $marker = $base.'/bootstrap/cache/kaevcms-framework-cache.json';
            $build = static function (string $version) use ($files, $caches, $marker, $base): void {
                $files->put($base.'/.env', "APP_ENV=production\n");
                touch($base.'/.env', time() - 60);
                foreach ($caches as $cache) {
                    $files->put($cache, "<?php return [];\n");
                }
                $files->put($marker, json_encode(['version' => $version], JSON_THROW_ON_ERROR));
                clearstatcache();
            };
            $exists = static fn (): array => array_map('is_file', [...$caches, $marker]);

            $files->put($base.'/VERSION', "1.2.3\n");
            $build('1.2.3');
            FrameworkCache::discardStale($app);
            clearstatcache();
            $this->assertSame([true, true, true, true], $exists(), 'Current caches must be kept.');

            touch($base.'/.env', time() + 60);
            clearstatcache();
            FrameworkCache::discardStale($app);
            clearstatcache();
            $this->assertSame([false, false, false, false], $exists(), 'An edited .env must discard the caches.');

            $build('1.2.2');
            FrameworkCache::discardStale($app);
            clearstatcache();
            $this->assertSame([false, false, false, false], $exists(), 'Caches of another release must be discarded.');

            $build('1.2.3');
            $files->delete($marker);
            FrameworkCache::discardStale($app);
            clearstatcache();
            $this->assertSame([true, true, true, false], $exists(), 'Caches an administrator built by hand stay while .env is older.');
        } finally {
            Container::setInstance($original);
            $files->deleteDirectory($base);
        }
    }

    public function test_a_production_request_builds_caches_without_database_values_or_module_routes(): void
    {
        $files = new Filesystem;
        $relative = 'storage/framework/testing/framework-cache-'.bin2hex(random_bytes(4));
        $root = base_path($relative);
        $storage = $root.'/storage';
        foreach (['framework/cache', 'framework/views', 'framework/sessions', 'logs', 'app/kaevcms'] as $directory) {
            $files->ensureDirectoryExists($storage.'/'.$directory);
        }
        $files->put($root.'/database.sqlite', '');
        $files->put($storage.'/app/kaevcms/installed-version.json', json_encode(['version' => KaevCMS::version()], JSON_THROW_ON_ERROR));

        $variables = [
            'APP_ENV' => 'production',
            'APP_RUNNING_IN_CONSOLE' => 'false',
            'DB_DATABASE' => $root.'/database.sqlite',
            'APP_CONFIG_CACHE' => $relative.'/config.php',
            'APP_EVENTS_CACHE' => $relative.'/events.php',
            'APP_ROUTES_CACHE' => $relative.'/routes.php',
        ];
        $previous = [];
        foreach ($variables as $name => $value) {
            $previous[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $original = $this->app;

        try {
            /** @var Application $site */
            $site = require base_path('bootstrap/app.php');
            $site->useStoragePath($storage);
            $site->instance('request', Request::create('https://example.test/'));
            $site->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class, RegisterFacades::class, RegisterProviders::class]);
            $site->boot();
            $this->assertTrue($site->isProduction());
            $this->assertFalse($site->runningInConsole());

            $site->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);
            $site->make(ModuleManager::class)->enable('daily-rewards');
            $site->make(MailSettings::class)->update([
                'host' => 'smtp.example.test',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'sender',
                'password' => self::MAIL_PASSWORD,
                'from_address' => 'sender@example.test',
                'from_name' => 'Sender',
                'admin_email' => '',
            ]);

            $site->make(FrameworkCache::class)->warm();

            $failure = $storage.'/framework/kaevcms-framework-cache.failed';
            $this->assertFileDoesNotExist($failure, (string) @file_get_contents($storage.'/logs/laravel.log'));
            foreach (['config.php', 'events.php', 'routes.php', 'kaevcms-framework-cache.json'] as $cache) {
                $this->assertFileExists($root.'/'.$cache);
            }
            $this->assertSame([], glob($root.'/*.tmp') ?: [], 'Temporary cache files must not be left behind.');

            $config = (string) file_get_contents($root.'/config.php');
            $this->assertStringNotContainsString(self::MAIL_PASSWORD, $config);
            $this->assertStringNotContainsString('smtp.example.test', $config, 'Mail settings from the database must stay out of the cache.');

            $routes = (string) file_get_contents($root.'/routes.php');
            $this->assertStringContainsString("'login'", $routes);
            $this->assertStringNotContainsString('modules/daily-rewards', $routes, 'Module routes are added at runtime, never cached.');
            $this->assertSame($site, Container::getInstance(), 'The site must get its container back after Laravel boots fresh applications.');
        } finally {
            foreach ($previous as $name => [$server, $env]) {
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
            }
            Container::setInstance($original);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($original);
            $files->deleteDirectory($root);
        }
    }
}
