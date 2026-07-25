<?php

namespace App\Http\Middleware;

use App\Support\Modules\ModuleManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $installedModule = $this->modules->inspect($module);

        if (in_array($installedModule['status'], ['runtime_error', 'migration_error'], true)) {
            abort(503, __('The requested module is temporarily unavailable.'));
        }

        abort_unless($installedModule['status'] === 'enabled', 404);

        return $next($request);
    }
}
