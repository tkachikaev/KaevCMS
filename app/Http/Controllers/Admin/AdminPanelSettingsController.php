<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithSettingsAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveServerMonitorSettingsRequest;
use App\Services\Admin\AdminPathSettings;
use App\Services\AuditLogger;
use App\Services\Servers\ServerMonitorSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class AdminPanelSettingsController extends Controller
{
    use InteractsWithSettingsAudit;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(
        ServerMonitorSettings $monitorSettings,
        AdminPathSettings $adminPathSettings,
    ): View {
        return view('admin.settings.admin-panel', [
            'monitorSettings' => $monitorSettings->values(),
            'monitorRefreshOptions' => ServerMonitorSettings::REFRESH_INTERVAL_OPTIONS,
            'adminPathSuffix' => $adminPathSettings->suffix(),
            'adminPath' => $adminPathSettings->displayPath(),
        ]);
    }

    public function updateMonitoring(
        SaveServerMonitorSettingsRequest $request,
        ServerMonitorSettings $monitorSettings,
    ): RedirectResponse {
        $before = $monitorSettings->values();
        $validated = $request->validated();
        $monitorSettings->update((int) $validated['refresh_interval_seconds']);
        $after = $monitorSettings->values();

        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.server_monitor_updated',
            target: __('Server monitoring'),
            details: ['changes' => $this->auditChanges($before, $after)],
        );

        return redirect()
            ->route('admin.settings.admin-panel')
            ->with('status', __('Server monitoring settings saved.'));
    }
}
