<?php

namespace Tests\Feature\Performance;

use App\Models\GameServer;
use App\Models\LoginServer;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * Production sites build Laravel's caches by themselves (FrameworkCache); see tests/load/RESULTS.md.
 */
class FrameworkCacheTest extends TestCase
{
    /** Every kind of stored secret the CMS decrypts at run time. */
    private const SECRETS = [
        'SMTP' => 'smtp-secret-must-stay-in-database',
        'game server' => 'game-db-secret-must-stay-in-database',
        'login server' => 'login-db-secret-must-stay-in-database',
        'payment provider' => 'provider-key-must-stay-in-database',
    ];

    private const MODULES = ['daily-rewards', 'donations', 'promo-codes', 'support-tickets'];

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

    public function test_a_production_request_builds_caches_without_secrets_and_every_module_keeps_its_pages(): void
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
            foreach (self::MODULES as $module) {
                $site->make(ModuleManager::class)->enable($module);
            }
            $loginServer = LoginServer::factory()->create(['database_password' => self::SECRETS['login server']]);
            GameServer::factory()->for($loginServer)->create(['database_password' => self::SECRETS['game server']]);
            DB::table('module_donation_provider_settings')->insert([
                'provider_code' => 'yoomoney', 'enabled' => true, 'mode' => 'live', 'currency_codes' => '["RUB"]',
                'credentials_ciphertext' => Crypt::encryptString(json_encode(['secret' => self::SECRETS['payment provider']], JSON_THROW_ON_ERROR)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $site->make(MailSettings::class)->update([
                'host' => 'smtp.example.test',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'sender',
                'password' => self::SECRETS['SMTP'],
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

            foreach (['config.php', 'events.php', 'routes.php'] as $cache) {
                $contents = (string) file_get_contents($root.'/'.$cache);
                foreach (self::SECRETS as $kind => $secret) {
                    $this->assertStringNotContainsString($secret, $contents, "The {$kind} password must never reach {$cache}.");
                }
            }
            $config = (string) file_get_contents($root.'/config.php');
            $this->assertStringNotContainsString('smtp.example.test', $config, 'Mail settings from the database must stay out of the cache.');

            $routes = (string) file_get_contents($root.'/routes.php');
            $this->assertStringContainsString("'login'", $routes);
            foreach (self::MODULES as $module) {
                $this->assertStringNotContainsString('modules/'.$module, $routes, 'Module routes are added at runtime, never cached.');
            }
            $this->assertSame($site, Container::getInstance(), 'The site must get its container back after Laravel boots fresh applications.');

            // The next request runs on these caches: every enabled module still has its pages.
            /** @var Application $next */
            $next = require base_path('bootstrap/app.php');
            $next->useStoragePath($storage);
            $next->instance('request', Request::create('https://example.test/'));
            $next->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class, RegisterFacades::class, RegisterProviders::class]);
            $next->boot();
            $this->assertTrue($next->configurationIsCached() && $next->routesAreCached());
            $uris = array_map(static fn ($route): string => $route->uri(), $next['router']->getRoutes()->getRoutes());
            foreach (self::MODULES as $module) {
                $this->assertContains('modules/'.$module, $uris, "The {$module} module lost its public page with cached routes.");
                $this->assertNotEmpty(preg_grep('#^\{adminPath\}/extensions/'.preg_quote($module, '#').'#', $uris), "The {$module} module lost its admin pages with cached routes.");
            }
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
