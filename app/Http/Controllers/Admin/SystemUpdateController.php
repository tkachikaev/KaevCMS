<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApplySystemUpdateRequest;
use App\Http\Requests\Admin\RecoverSystemUpdateRequest;
use App\Http\Requests\Admin\UploadSystemUpdateRequest;
use App\Models\Admin;
use App\Models\SystemUpdate;
use App\Services\AuditLogger;
use App\Services\Releases\InstalledVersion;
use App\Services\Updates\InspectedUpdatePackage;
use App\Services\Updates\SystemUpdateInstaller;
use App\Services\Updates\SystemUpdateRecovery;
use App\Services\Updates\UpdateInstallationLayout;
use App\Services\Updates\UpdatePackageInspector;
use App\Services\Updates\UpdatePreflight;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

final class SystemUpdateController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(
        InstalledVersion $installedVersion,
        UpdateInstallationLayout $layout,
        UpdatePackageInspector $inspector,
    ): View {
        $admin = $this->owner();

        return view('admin.settings.updates.index', [
            'currentVersion' => $this->currentVersion($installedVersion),
            'installationType' => $layout->type(),
            'zipAvailable' => $inspector->available(),
            'stagedUpdates' => SystemUpdate::query()
                ->where('status', SystemUpdate::STATUS_STAGED)
                ->latest()
                ->get(),
            'history' => SystemUpdate::query()
                ->where('status', '!=', SystemUpdate::STATUS_STAGED)
                ->latest()
                ->limit(30)
                ->get(),
            'admin' => $admin,
        ]);
    }

    public function store(
        UploadSystemUpdateRequest $request,
        InstalledVersion $installedVersion,
        UpdatePackageInspector $inspector,
    ): RedirectResponse {
        $admin = $this->owner();
        $currentVersion = $this->currentVersion($installedVersion);
        $directory = storage_path('app/kaevcms/updates/packages');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return back()->withErrors(['package' => __('Unable to create the update package directory.')]);
        }

        $uuid = (string) Str::uuid();
        $archivePath = $directory.'/'.$uuid.'.zip';
        $uploaded = $request->file('package');
        if ($uploaded === null) {
            return back()->withErrors(['package' => __('Select an update package.')]);
        }

        $package = null;

        try {
            $uploaded->move($directory, $uuid.'.zip');
            $package = $inspector->inspect($archivePath, $currentVersion);
            $update = SystemUpdate::query()->create([
                'uuid' => $uuid,
                'admin_id' => $admin->id,
                'package_id' => $package->packageId,
                'from_version' => $package->currentVersion,
                'target_version' => $package->targetVersion,
                'status' => SystemUpdate::STATUS_STAGED,
                'phase' => null,
                'installation_type' => $package->installationType,
                'package_path' => 'kaevcms/updates/packages/'.$uuid.'.zip',
                'package_sha256' => $package->archiveSha256,
                'file_count' => count($package->files),
                'delete_count' => count($package->delete),
                'manifest' => $package->manifest,
            ]);

            try {
                $this->auditLogger->success(
                    category: 'system',
                    action: 'system.update_package_uploaded',
                    target: "KaevCMS {$package->targetVersion}",
                    details: [
                        'package_id' => $package->packageId,
                        'from_version' => $package->currentVersion,
                        'target_version' => $package->targetVersion,
                    ],
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        } catch (Throwable $exception) {
            @unlink($archivePath);

            return back()->withErrors([
                'package' => __('The update package was rejected: :message', ['message' => $exception->getMessage()]),
            ]);
        } finally {
            if ($package instanceof InspectedUpdatePackage) {
                $this->removeDirectory($package->stagingPath);
            }
        }

        return redirect()
            ->route('admin.settings.system.updates.show', $update)
            ->with('status', __('The update package was verified. Review the preflight checks before installation.'));
    }

    public function show(
        SystemUpdate $systemUpdate,
        InstalledVersion $installedVersion,
        UpdatePackageInspector $inspector,
        UpdatePreflight $preflight,
    ): View {
        $this->owner();
        $package = null;
        $checks = [];
        $inspectionError = null;

        if ($systemUpdate->isStaged()) {
            try {
                $package = $inspector->inspect(
                    $this->absolutePackagePath($systemUpdate),
                    $this->currentVersion($installedVersion),
                );
                $checks = $preflight->inspect($package);
            } catch (Throwable $exception) {
                $inspectionError = $exception->getMessage();
            }
        }

        $maintenanceSecret = null;
        if ($systemUpdate->isStaged() || $systemUpdate->status === SystemUpdate::STATUS_APPLYING) {
            $maintenanceSecret = $this->maintenanceSecret($systemUpdate);
        }

        try {
            return view('admin.settings.updates.show', [
                'update' => $systemUpdate,
                'package' => $package,
                'checks' => $checks,
                'checksPassed' => $checks !== [] && $preflight->passes($checks),
                'inspectionError' => $inspectionError,
                'logTail' => $this->logTail($systemUpdate),
                'recoveryUrl' => $maintenanceSecret !== null ? url('/'.$maintenanceSecret) : null,
            ]);
        } finally {
            if ($package instanceof InspectedUpdatePackage) {
                $this->removeDirectory($package->stagingPath);
            }
        }
    }

    public function apply(
        ApplySystemUpdateRequest $request,
        SystemUpdate $systemUpdate,
        SystemUpdateInstaller $installer,
    ): RedirectResponse {
        $this->owner();

        $maintenanceSecret = request()->session()->get($this->maintenanceSessionKey($systemUpdate));
        if (! is_string($maintenanceSecret) || $maintenanceSecret === '') {
            return redirect()
                ->route('admin.settings.system.updates.show', $systemUpdate)
                ->withErrors(['update' => __('The update recovery secret is missing. Reload the page and try again.')]);
        }

        try {
            $installer->apply($systemUpdate, $maintenanceSecret);
        } catch (Throwable $exception) {
            $fresh = $systemUpdate->fresh();
            if (! $fresh instanceof SystemUpdate || $fresh->status !== SystemUpdate::STATUS_APPLYING) {
                request()->session()->forget($this->maintenanceSessionKey($systemUpdate));
            }

            return redirect()
                ->route('admin.settings.system.updates.show', $systemUpdate)
                ->withErrors([
                    'update' => __('The update failed: :message', ['message' => $exception->getMessage()]),
                ]);
        }

        request()->session()->forget($this->maintenanceSessionKey($systemUpdate));

        return redirect()
            ->route('admin.settings.system.updates.show', $systemUpdate)
            ->with('status', __('KaevCMS was updated successfully.'));
    }

    public function recover(
        RecoverSystemUpdateRequest $request,
        SystemUpdate $systemUpdate,
        SystemUpdateRecovery $recovery,
    ): RedirectResponse {
        $this->owner();

        try {
            $recovery->recover($systemUpdate);
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.settings.system.updates.show', $systemUpdate)
                ->withErrors([
                    'update' => $exception->getMessage(),
                ]);
        }

        $request->session()->forget($this->maintenanceSessionKey($systemUpdate));

        return redirect()
            ->route('admin.settings.system.updates.show', $systemUpdate)
            ->with('status', __('The interrupted update was rolled back and maintenance mode was disabled.'));
    }

    public function destroy(SystemUpdate $systemUpdate): RedirectResponse
    {
        $this->owner();
        abort_unless($systemUpdate->isStaged(), 409);

        $path = $this->absolutePackagePath($systemUpdate, false);
        if (is_file($path)) {
            @unlink($path);
        }

        request()->session()->forget($this->maintenanceSessionKey($systemUpdate));

        $systemUpdate->forceFill([
            'status' => SystemUpdate::STATUS_DISCARDED,
            'completed_at' => now(),
        ])->save();

        try {
            $this->auditLogger->success(
                category: 'system',
                action: 'system.update_package_discarded',
                target: "KaevCMS {$systemUpdate->target_version}",
                details: ['package_id' => $systemUpdate->package_id],
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('admin.settings.system.updates.index')
            ->with('status', __('The staged update package was discarded.'));
    }

    public function log(SystemUpdate $systemUpdate): Response
    {
        $this->owner();
        $path = $this->absoluteLogPath($systemUpdate);
        abort_unless($path !== null && is_file($path), 404);

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function owner(): Admin
    {
        $admin = request()->user('admin');
        abort_unless($admin instanceof Admin && $admin->isOwner(), 403);

        return $admin;
    }

    private function currentVersion(InstalledVersion $installedVersion): string
    {
        try {
            return $installedVersion->current() ?? cms_version();
        } catch (Throwable) {
            return cms_version();
        }
    }

    private function absolutePackagePath(SystemUpdate $update, bool $mustExist = true): string
    {
        $storedPath = str_replace('\\', '/', $update->package_path);
        if (! str_starts_with($storedPath, 'kaevcms/updates/packages/') || str_contains($storedPath, '..')) {
            throw new RuntimeException(__('The staged update package path is invalid.'));
        }

        $relative = str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
        $path = storage_path('app'.DIRECTORY_SEPARATOR.$relative);

        if ($mustExist && ! is_file($path)) {
            throw new RuntimeException(__('The staged update package is missing.'));
        }

        return $path;
    }

    private function absoluteLogPath(SystemUpdate $update): ?string
    {
        if (! is_string($update->log_path) || $update->log_path === '') {
            return null;
        }

        $relative = str_replace('\\', '/', $update->log_path);
        if (! str_starts_with($relative, 'storage/logs/') || str_contains($relative, '..')) {
            return null;
        }

        return base_path(str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }

    private function logTail(SystemUpdate $update): ?string
    {
        $path = $this->absoluteLogPath($update);
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file($path, FILE_IGNORE_NEW_LINES);
        if (! is_array($contents)) {
            return null;
        }

        return implode("\n", array_slice($contents, -80));
    }

    private function maintenanceSecret(SystemUpdate $update): string
    {
        $key = $this->maintenanceSessionKey($update);
        $secret = request()->session()->get($key);
        if (! is_string($secret) || preg_match('/\A[a-zA-Z0-9]{32,128}\z/', $secret) !== 1) {
            $secret = Str::random(48);
            request()->session()->put($key, $secret);
        }

        return $secret;
    }

    private function maintenanceSessionKey(SystemUpdate $update): string
    {
        return 'kaevcms.system-update.'.$update->uuid.'.maintenance-secret';
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
