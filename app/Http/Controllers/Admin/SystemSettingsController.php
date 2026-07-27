<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Servers\ExternalDatabaseDiagnostics;
use App\Services\SystemInformation;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class SystemSettingsController extends Controller
{
    public function system(SystemInformation $systemInformation): View
    {
        return view('admin.settings.system', [
            'system' => $systemInformation->collect(),
        ]);
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
