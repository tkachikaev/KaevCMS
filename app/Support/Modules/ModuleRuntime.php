<?php

namespace App\Support\Modules;

use App\Models\ModuleState;
use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Translation\Translator;
use RuntimeException;
use Throwable;

final class ModuleRuntime
{
    /** @var array<string, true> */
    private array $autoloaded = [];

    /** @var array<string, true> */
    private array $booted = [];

    public function __construct(
        private readonly Application $app,
        private readonly Filesystem $files,
    ) {}

    public function bootEnabled(ModuleManager $modules): void
    {
        foreach ($modules->enabled() as $module) {
            try {
                $this->bootModule($module);
            } catch (Throwable $exception) {
                $this->reportFailure($module, $exception, 'boot');
            } finally {
                // A partially registered route must immediately see runtime_error,
                // while a recovered module must stop exposing an old error state.
                $modules->refresh();
            }
        }
    }

    /** @param array<string, mixed> $module */
    public function bootModule(array $module): void
    {
        $id = (string) $module['id'];
        if (isset($this->booted[$id])) {
            return;
        }

        if (
            ($module['enabled'] ?? false) !== true
            || ($module['valid'] ?? false) !== true
            || ($module['compatible'] ?? false) !== true
            || ! in_array($module['status'] ?? null, ['enabled', 'runtime_error'], true)
        ) {
            throw new RuntimeException("Module [$id] is not ready for runtime loading.");
        }

        $this->registerAutoload($module);
        $context = new ModuleContext($id, (string) $module['path'], (array) $module['manifest']);

        $bootstrapPath = $module['bootstrap_path'] ?? null;
        if (is_string($bootstrapPath)) {
            $bootstrap = require $bootstrapPath;
            if (! is_callable($bootstrap)) {
                throw new RuntimeException("Module [$id] bootstrap file must return a callable.");
            }

            $bootstrap($this->app, $context);
        }

        $viewsPath = $module['views_path'] ?? null;
        if (is_string($viewsPath)) {
            View::addNamespace($context->viewNamespace(), $viewsPath);
        }

        $langPath = $module['lang_path'] ?? null;
        if (is_string($langPath)) {
            /** @var Translator $translator */
            $translator = $this->app->make('translator');
            $translator->addNamespace($context->translationNamespace(), $langPath);
            $translator->addJsonPath($langPath);
        }

        // Module routes intentionally stay outside Laravel's core route cache.
        // They are added after the cached/core routes on every normal request,
        // so disabling or replacing a module cannot leave executable stale routes.
        if ($this->shouldRegisterRoutes()) {
            $routes = Route::getRoutes();
            $routeCountBeforeModule = count($routes->getRoutes());
            $routePaths = (array) ($module['route_paths'] ?? []);
            if (isset($routePaths['web']) && is_string($routePaths['web'])) {
                Route::middleware(['web', 'module.enabled:'.$id])
                    ->prefix('modules/'.$id)
                    ->name($context->publicRouteNamePrefix())
                    ->group($routePaths['web']);
            }

            if (isset($routePaths['admin']) && is_string($routePaths['admin'])) {
                Route::pattern('adminPath', 'admin(?:-[a-z0-9]+(?:-[a-z0-9]+)*)?');
                Route::middleware(['web', 'admin.path', 'admin.headers', 'admin.auth', 'admin.access', 'module.enabled:'.$id])
                    ->prefix('{adminPath}/extensions/'.$id)
                    ->name($context->adminRouteNamePrefix())
                    ->group($routePaths['admin']);
            }

            // Module routes are registered after Laravel finishes its normal route boot.
            // Fluent ->name() calls happen after a route is initially added, so re-adding
            // only the newly registered routes updates named/action lookups for both the
            // normal and compiled route collections without touching cached core routes.
            foreach (array_slice($routes->getRoutes(), $routeCountBeforeModule) as $route) {
                $routes->add($route);
            }
        }

        $this->booted[$id] = true;
        $this->clearFailure($id);
    }

    private function shouldRegisterRoutes(): bool
    {
        if (! $this->app->runningInConsole()) {
            return true;
        }

        $arguments = array_map('strval', (array) ($_SERVER['argv'] ?? []));

        return array_intersect(['route:cache', 'optimize'], $arguments) === [];
    }

    /** @param array<string, mixed> $module */
    private function registerAutoload(array $module): void
    {
        $id = (string) $module['id'];
        if (isset($this->autoloaded[$id])) {
            return;
        }

        $namespace = $module['namespace'] ?? null;
        $autoloadPath = $module['autoload_path'] ?? null;
        if (! is_string($namespace) || ! is_string($autoloadPath)) {
            $this->autoloaded[$id] = true;

            return;
        }

        if (! $this->files->isDirectory($autoloadPath)) {
            throw new RuntimeException("Module [$id] autoload directory is unavailable.");
        }

        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (($loader instanceof ClassLoader) === false) {
            throw new RuntimeException('Composer PSR-4 autoloader is unavailable.');
        }

        $loader->addPsr4($namespace, $autoloadPath, true);
        $this->autoloaded[$id] = true;
    }

    /** @param array<string, mixed> $module */
    private function reportFailure(array $module, Throwable $exception, string $stage): void
    {
        $id = (string) ($module['id'] ?? 'unknown');

        Log::error('Enabled KaevCMS module could not be loaded.', [
            'module_id' => $id,
            'module_version' => (string) ($module['version'] ?? 'unknown'),
            'stage' => $stage,
            'exception' => $exception::class,
        ]);

        try {
            if (Schema::hasTable('cms_modules')) {
                ModuleState::query()->whereKey($id)->update([
                    'last_error' => mb_substr($stage.': '.$exception::class, 0, 190),
                    'last_error_at' => now(),
                ]);
            }
        } catch (Throwable) {
            // Runtime diagnostics must never make the CMS unavailable.
        }
    }

    private function clearFailure(string $id): void
    {
        try {
            if (Schema::hasTable('cms_modules')) {
                ModuleState::query()
                    ->whereKey($id)
                    ->whereNotNull('last_error')
                    ->update([
                        'last_error' => null,
                        'last_error_at' => null,
                    ]);
            }
        } catch (Throwable) {
            // A module that booted successfully remains usable even if diagnostics cannot be updated.
        }
    }
}
