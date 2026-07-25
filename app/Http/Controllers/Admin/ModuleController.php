<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

class ModuleController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(ModuleManager $modules): View
    {
        $installedModules = $modules->installed();

        return view('admin.modules.index', [
            'modules' => $installedModules,
            'enabledCount' => collect($installedModules)->where('enabled', true)->count(),
            'problemCount' => collect($installedModules)
                ->whereIn('status', [
                    'invalid',
                    'incompatible',
                    'missing',
                    'update_pending',
                    'runtime_error',
                    'migration_pending',
                    'migration_error',
                    'migration_modified',
                    'migration_unavailable',
                ])
                ->count(),
        ]);
    }

    public function enable(Request $request, string $module, ModuleManager $modules): RedirectResponse
    {
        $previousModule = $modules->inspect($module);

        try {
            $resolvedModule = $modules->enable($module);
        } catch (RuntimeException $exception) {
            $modules->refresh();
            $failedModule = $modules->inspect($module);
            $action = $failedModule['status'] === 'migration_error'
                ? 'module.migration_failed'
                : 'module.enable_failed';

            $this->auditLogger->failed(
                category: 'module',
                action: $action,
                target: $module,
                details: ['reason' => $exception->getMessage()],
            );

            return redirect()
                ->route('admin.modules.index')
                ->withErrors(['module' => $exception->getMessage()]);
        }

        $migrationResult = (array) ($resolvedModule['migration_result'] ?? []);
        $appliedMigrations = (array) ($migrationResult['applied'] ?? []);
        $appliedCount = (int) ($migrationResult['applied_count'] ?? 0);

        Log::notice('KaevCMS module enabled.', [
            'admin_id' => Auth::guard('admin')->id(),
            'module_id' => $module,
            'module_version' => $resolvedModule['version'],
            'applied_migrations' => $appliedCount,
            'ip_address' => $request->ip(),
        ]);

        $action = match (true) {
            $previousModule['update_available'] => 'module.update_approved',
            $previousModule['enabled'] && $appliedCount > 0 => 'module.database_updated',
            $appliedCount > 0 => 'module.installed',
            default => 'module.enabled',
        };

        $this->auditLogger->success(
            category: 'module',
            action: $action,
            target: $module,
            details: [
                'module_id' => $module,
                'module_name' => $resolvedModule['name'],
                'module_version' => $resolvedModule['version'],
                'previous_module_version' => $previousModule['stored_version'],
                'applied_migrations' => $appliedMigrations,
            ],
        );

        $status = $appliedCount > 0
            ? __('Module :module enabled after applying :count database migration(s).', [
                'module' => $resolvedModule['name'],
                'count' => $appliedCount,
            ])
            : __('Module :module enabled. It will be active from the next request.', ['module' => $resolvedModule['name']]);

        return redirect()
            ->route('admin.modules.index')
            ->with('status', $status);
    }

    public function disable(Request $request, string $module, ModuleManager $modules): RedirectResponse
    {
        try {
            $resolvedModule = $modules->disable($module);
        } catch (RuntimeException $exception) {
            $this->auditLogger->failed(
                category: 'module',
                action: 'module.disable_failed',
                target: $module,
                details: ['reason' => $exception->getMessage()],
            );

            return redirect()
                ->route('admin.modules.index')
                ->withErrors(['module' => $exception->getMessage()]);
        }

        Log::notice('KaevCMS module disabled.', [
            'admin_id' => Auth::guard('admin')->id(),
            'module_id' => $module,
            'module_version' => $resolvedModule['version'],
            'ip_address' => $request->ip(),
        ]);

        $this->auditLogger->success(
            category: 'module',
            action: 'module.disabled',
            target: $module,
            details: [
                'module_id' => $module,
                'module_name' => $resolvedModule['name'],
                'module_version' => $resolvedModule['version'],
            ],
        );

        return redirect()
            ->route('admin.modules.index')
            ->with('status', __('Module :module disabled. Its database data was preserved.', ['module' => $resolvedModule['name']]));
    }
}
