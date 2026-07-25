<?php

namespace App\Http\Controllers;

use App\Services\Servers\ServerMonitorCoordinator;
use App\Services\Servers\ServerStatusOverview;
use App\Services\Servers\ServerStatusPayload;
use Illuminate\Http\JsonResponse;

final class ServerMonitorStatusController extends Controller
{
    public function __invoke(
        ServerMonitorCoordinator $coordinator,
        ServerStatusOverview $statuses,
        ServerStatusPayload $payload,
    ): JsonResponse {
        $refresh = $coordinator->refreshIfDue();
        $monitor = $payload->forPublic($statuses->get());

        return $this->response([
            'refreshing' => $refresh['refreshing'],
            'fresh' => ! $coordinator->isDue(),
            'monitor' => $monitor,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function response(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
