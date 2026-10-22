<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Dashboard\AdminDashboardOverview;
use App\Services\Dashboard\DashboardAttention;
use App\Services\Dashboard\DashboardTrends;
use App\Services\Infrastructure\DashboardPlayerOverview;
use App\Services\Infrastructure\RuntimeDiagnostics;
use App\Services\Infrastructure\StorageOverview;
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
        StorageOverview $storageOverview,
        DashboardPlayerOverview $playerOverview,
        AdminDashboardOverview $dashboardOverview,
        DashboardAttention $attention,
    ): View {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        $overview = $dashboardOverview->forAdmin($admin);
        $monitor = $statuses->get();
        $mailDelivery = $mailDeliveries->overview();
        $runtime = $admin->hasPermission(AdminPermission::SystemView) ? $runtimeDiagnostics->overview() : null;
        $storage = $admin->hasPermission(AdminPermission::SystemView) ? $storageOverview->collect() : null;
        $players = $admin->hasPermission(AdminPermission::UsersView) ? $playerOverview->forAdmin($admin) : null;

        return view('admin.dashboard', [
            'admin' => $admin,
            'overview' => $overview,
            'chartWidgets' => DashboardTrends::widgets($overview['registrations'], $overview['online'], $overview['donations']),
            'attention' => $attention->collect($admin, $monitor, $players, $overview, $mailDelivery, $runtime, $storage),
            'monitor' => $monitor,
            'monitorRefreshDue' => $monitorCoordinator->isDue(),
            'mailSettings' => $mailSettings->values(),
            'mailDelivery' => $mailDelivery,
            'runtime' => $runtime,
            'storageOverview' => $storage,
            'playerOverview' => $players,
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
