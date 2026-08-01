<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Diagnostics\DiagnosticPackageBuilder;
use App\Services\Servers\ExternalDatabaseDiagnostics;
use App\Services\SystemInformation;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class SystemSettingsController extends Controller
{
    public function system(SystemInformation $systemInformation): View
    {
        return view('admin.settings.system', [
            'system' => $systemInformation->collect(),
            'diagnosticPackageAvailable' => class_exists(\ZipArchive::class),
        ]);
    }

    public function downloadDiagnostics(
        DiagnosticPackageBuilder $builder,
        AuditLogger $audit,
    ): BinaryFileResponse|RedirectResponse {
        try {
            $package = $builder->build();
        } catch (Throwable $exception) {
            report($exception);
            $audit->failed(
                category: 'system',
                action: 'system.diagnostics_downloaded',
                details: ['exception_class' => $exception::class],
            );

            return redirect()
                ->route('admin.settings.system')
                ->withErrors([
                    'diagnostics' => __('Could not create the diagnostic package. Check the PHP zip extension and storage permissions.'),
                ]);
        }

        $audit->success(
            category: 'system',
            action: 'system.diagnostics_downloaded',
            target: 'KaevCMS diagnostic package',
            details: ['file_name' => $package->name],
        );

        $response = response()
            ->download($package->path, $package->name, ['Content-Type' => 'application/zip']);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response->deleteFileAfterSend(true);
    }

    public function refreshExternalDatabases(
        ExternalDatabaseDiagnostics $diagnostics,
        AuditLogger $audit,
    ): RedirectResponse {
        $result = $diagnostics->refresh();
        $audit->success(
            category: 'admin',
            action: 'external_databases.diagnostics_refreshed',
            details: $result,
        );

        return redirect()
            ->route('admin.settings.system')
            ->with('status', __('external_databases.refresh_complete', [
                'successful' => $result['successful'],
                'failed' => $result['failed'],
            ]));
    }
}
