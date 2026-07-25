<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Infrastructure\RuntimeDiagnostics;
use App\Services\Mail\MailDeliveryMonitor;
use App\Services\MailSettings;
use App\Services\Servers\ServerMonitorCoordinator;
use App\Services\Servers\ServerStatusOverview;
use App\Services\Servers\ServerStatusPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        ServerStatusOverview $statuses,
        ServerMonitorCoordinator $monitorCoordinator,
        MailSettings $mailSettings,
        MailDeliveryMonitor $mailDeliveries,
        RuntimeDiagnostics $runtimeDiagnostics,
    ): View {
        $admin = Auth::guard('admin')->user();

        return view('admin.dashboard', [
            'admin' => $admin,
            'monitor' => $statuses->get(),
            'monitorRefreshDue' => $monitorCoordinator->isDue(),
            'mailSettings' => $mailSettings->values(),
            'mailDelivery' => $mailDeliveries->overview(),
            'runtime' => $admin instanceof Admin && $admin->hasPermission(AdminPermission::SystemView)
                ? $runtimeDiagnostics->overview()
                : null,
        ]);
    }

    public function status(
        ServerMonitorCoordinator $monitorCoordinator,
        ServerStatusOverview $statuses,
        ServerStatusPayload $payload,
    ): JsonResponse {
        $refresh = $monitorCoordinator->refreshIfDue();
        $monitor = $payload->forAdmin($statuses->get());
        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof Admin || ! $admin->hasPermission(AdminPermission::ServersView)) {
            $monitor['game_servers'] = [];
            $monitor['login_servers'] = [];
        }

        return response()
            ->json([
                'refreshing' => $refresh['refreshing'],
                'fresh' => ! $monitorCoordinator->isDue(),
                'monitor' => $monitor,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function refresh(ServerMonitorCoordinator $monitorCoordinator): RedirectResponse
    {
        $result = $monitorCoordinator->refreshIfDue(force: true);

        return redirect()
            ->route('admin.dashboard')
            ->with('status', $result['refreshing']
                ? __('Server status refresh is already running.')
                : __('Server status refreshed.'));
    }
}
