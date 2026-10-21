<?php

namespace App\Support\Modules;

use App\Models\ModuleState;
use App\Support\Database\TableExistence;
use App\Support\FrameworkCache;
use Illuminate\Foundation\Application;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Translation\Translator;
use RuntimeException;
use Throwable;

final class ModuleRuntime
{
    /** @var array<string, true> */
    private array $booted = [];

    public function __construct(
        private readonly Application $app,
        private readonly ModuleAutoloader $autoloader,
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

        $this->autoloader->register($module);
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

            $this->refreshRouteLookups();
        }

        $this->booted[$id] = true;
        $this->clearFailure($id);
    }

    /**
     * Module routes are registered after Laravel finishes its normal route boot, and fluent
     * ->name() calls happen after a route is added, so the name and action lookups must be
     * rebuilt. With the core route cache the module routes live in the compiled collection's
     * small fallback collection, whose refresh Laravel skips; rebuilding it directly avoids
     * creating every cached route object again on each request.
     */
    private function refreshRouteLookups(): void
    {
        $routes = Route::getRoutes();
        if ($routes instanceof CompiledRouteCollection) {
            $routes = (fn (): RouteCollection => $this->routes)->call($routes);
        }

        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    private function shouldRegisterRoutes(): bool
    {
        return ! FrameworkCache::building($this->app);
    }

    /** @param array<string, mixed> $module */
    private function reportFailure(array $module, Throwable $exception, string $stage): void
    {
        $id = (string) ($module['id'] ?? 'unknown');

        Log::error('Enabled KaevCMS module could not be loaded.', [
            'module_id' => $id,
            'module_version' => (string) ($module['version'] ?? 'unknown'),
            'stage' => $stage,
            'exception_class' => $exception::class,
        ]);

        try {
            if (app(TableExistence::class)->has('cms_modules')) {
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
            if (app(TableExistence::class)->has('cms_modules')) {
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
