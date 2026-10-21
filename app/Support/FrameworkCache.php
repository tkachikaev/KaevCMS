<?php

namespace App\Support;

use App\Services\Releases\InstalledVersion;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Keeps Laravel's configuration, event and route caches built on production sites without
 * an administrator running `php artisan optimize`. Installs, updates and `optimize:clear`
 * only remove the caches; the first ordinary request after them builds new ones once its
 * response has been sent. A changed `.env` or release version discards them again.
 */
final class FrameworkCache
{
    private const FAILURE_MARKER = 'framework/kaevcms-framework-cache.failed';

    private const LOCK = 'framework/kaevcms-framework-cache.lock';

    private const RETRY_AFTER_FAILURE_SECONDS = 3600;

    /** Set while this class builds caches in a web request; Laravel boots fresh applications then. */
    private static bool $building = false;

    public function __construct(private readonly Application $app) {}

    /**
     * True while configuration or routes are being cached, by this class or by artisan.
     * Values from the database and module routes must not be written into the caches then.
     */
    public static function building(ApplicationContract $app): bool
    {
        if (self::$building) {
            return true;
        }

        if (! $app->runningInConsole()) {
            return false;
        }

        $arguments = array_map('strval', (array) ($_SERVER['argv'] ?? []));

        return array_intersect(['config:cache', 'route:cache', 'optimize'], $arguments) !== [];
    }

    /**
     * Called from bootstrap/app.php before configuration loads: a cache built before the
     * last `.env` edit or for another release version would silently keep old values.
     */
    public static function discardStale(Application $app): void
    {
        $config = $app->getCachedConfigPath();
        $marker = self::markerPath($app);
        $stale = false;

        if (is_file($marker)) {
            $stale = self::markerVersion($marker) !== self::codeVersion($app);
        }

        $environment = $app->environmentFilePath();
        if (! $stale && is_file($config) && is_file($environment)) {
            $stale = (int) @filemtime($environment) > (int) @filemtime($config);
        }

        if (! $stale) {
            return;
        }

        foreach ([$config, $app->getCachedRoutesPath(), $app->getCachedEventsPath(), $marker] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Builds missing caches after the response; does nothing when they already exist. */
    public function warm(): void
    {
        if (! $this->shouldWarm()) {
            return;
        }

        $lockPath = $this->app->storagePath(self::LOCK);
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return;
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }

        $failure = $this->app->storagePath(self::FAILURE_MARKER);

        try {
            if ($this->cachesExist() || $this->failedRecently($failure)) {
                return;
            }

            self::$building = true;
            $this->build('APP_CONFIG_CACHE', 'config:cache', $this->app->getCachedConfigPath());
            $this->build('APP_EVENTS_CACHE', 'event:cache', $this->app->getCachedEventsPath());
            $this->build('APP_ROUTES_CACHE', 'route:cache', $this->app->getCachedRoutesPath());
            $this->writeMarker();

            if (is_file($failure)) {
                @unlink($failure);
            }
        } catch (Throwable $exception) {
            @touch($failure);

            try {
                Log::warning('KaevCMS could not build the Laravel caches; the site keeps working without them.', [
                    'exception_class' => $exception::class,
                    'message' => mb_substr($exception->getMessage(), 0, 300),
                ]);
            } catch (Throwable) {
                // A missing cache only makes the site slower, never unavailable.
            }
        } finally {
            self::$building = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function shouldWarm(): bool
    {
        if (! $this->app->isProduction() || $this->app->runningInConsole() || $this->cachesExist()) {
            return false;
        }

        try {
            // Not during an install or update: the version is marked only once they finish.
            return ! $this->app->isDownForMaintenance()
                && $this->app->make(InstalledVersion::class)->markerVersion() === self::codeVersion($this->app);
        } catch (Throwable) {
            return false;
        }
    }

    private function cachesExist(): bool
    {
        return $this->app->configurationIsCached()
            && $this->app->eventsAreCached()
            && $this->app->routesAreCached();
    }

    private function failedRecently(string $failure): bool
    {
        return is_file($failure) && (int) @filemtime($failure) > time() - self::RETRY_AFTER_FAILURE_SECONDS;
    }

    /**
     * Artisan writes the cache to a temporary file named through the cache path variable, so
     * requests running meanwhile never read a half-written file; it is then renamed in place.
     */
    private function build(string $variable, string $command, string $target): void
    {
        $basePath = rtrim($this->app->basePath(), '\\/').DIRECTORY_SEPARATOR;
        if (! str_starts_with($target, $basePath)) {
            throw new RuntimeException("The {$command} target is outside the application directory.");
        }

        // Laravel treats only "/" and "\" prefixes as absolute, so pass a path relative to the base.
        $relative = substr($target, strlen($basePath)).'.'.bin2hex(random_bytes(4)).'.tmp';
        $temporary = $basePath.$relative;
        $previous = [$_SERVER[$variable] ?? null, $_ENV[$variable] ?? null];
        $_SERVER[$variable] = $_ENV[$variable] = $relative;

        try {
            $exitCode = $this->app->make(ConsoleKernel::class)->call($command);
        } finally {
            $this->restoreVariable($variable, $previous);
            // Caching commands boot a fresh application, which takes over the global container.
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }

        if ($exitCode !== 0 || ! is_file($temporary)) {
            @unlink($temporary);

            throw new RuntimeException("{$command} did not produce a cache file.");
        }

        if (! @rename($temporary, $target)) {
            @unlink($temporary);

            throw new RuntimeException("{$command} could not move the cache file into place.");
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
    }

    /** @param array{0: mixed, 1: mixed} $previous */
    private function restoreVariable(string $variable, array $previous): void
    {
        if ($previous[0] === null) {
            unset($_SERVER[$variable]);
        } else {
            $_SERVER[$variable] = $previous[0];
        }

        if ($previous[1] === null) {
            unset($_ENV[$variable]);
        } else {
            $_ENV[$variable] = $previous[1];
        }
    }

    private function writeMarker(): void
    {
        $marker = self::markerPath($this->app);
        $temporary = $marker.'.'.bin2hex(random_bytes(4)).'.tmp';
        $contents = json_encode(['version' => self::codeVersion($this->app)], JSON_THROW_ON_ERROR);

        if (@file_put_contents($temporary, $contents) === false || ! @rename($temporary, $marker)) {
            @unlink($temporary);

            throw new RuntimeException('The framework cache marker could not be written.');
        }
    }

    /** Next to the configuration cache, wherever APP_CONFIG_CACHE puts it. */
    private static function markerPath(Application $app): string
    {
        return dirname($app->getCachedConfigPath()).DIRECTORY_SEPARATOR.'kaevcms-framework-cache.json';
    }

    private static function markerVersion(string $marker): ?string
    {
        $decoded = json_decode((string) @file_get_contents($marker), true);

        return is_array($decoded) && is_string($decoded['version'] ?? null) ? $decoded['version'] : null;
    }

    private static function codeVersion(Application $app): string
    {
        return trim((string) @file_get_contents($app->basePath('VERSION')));
    }
}
