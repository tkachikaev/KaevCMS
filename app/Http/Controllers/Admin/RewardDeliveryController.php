<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\GameRewardQueueGateway;
use App\Http\Controllers\Controller;
use App\Models\GameServer;
use App\Models\RewardDelivery;
use App\Services\GameAssets\GameAssetUrlResolver;
use App\Services\Rewards\RewardDeliveryReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class RewardDeliveryController extends Controller
{
    public function index(
        Request $request,
        GameAssetUrlResolver $assets,
        GameRewardQueueGateway $rewardQueue,
    ): View {
        $status = strtolower(trim((string) $request->query('status')));
        if (! in_array($status, RewardDelivery::STATUSES, true)) {
            $status = null;
        }

        $serverId = max(0, (int) $request->query('server'));
        $servers = GameServer::query()
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $selectedServer = $serverId > 0 ? $servers->firstWhere('id', $serverId) : null;
        $queueCapability = $selectedServer instanceof GameServer
            ? $rewardQueue->capabilities($selectedServer)
            : null;

        $query = RewardDelivery::query()
            ->with(['user', 'gameServer.translations', 'items'])
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($serverId > 0) {
            $query->where('game_server_id', $serverId);
        }

        $deliveries = $query->paginate(50)->withQueryString();
        $itemIconUrls = [];
        foreach ($deliveries as $delivery) {
            foreach ($delivery->items as $item) {
                $itemIconUrls[$item->id] = $assets->itemIcon($delivery->gameServer, $item->item_id);
            }
        }

        $statusCounts = RewardDelivery::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return view('admin.rewards.index', [
            'deliveries' => $deliveries,
            'itemIconUrls' => $itemIconUrls,
            'activeStatus' => $status,
            'activeServerId' => $serverId,
            'servers' => $servers,
            'selectedServer' => $selectedServer,
            'queueCapability' => $queueCapability,
            'statusCounts' => $statusCounts,
            'totalCount' => array_sum($statusCounts),
        ]);
    }

    public function reconcile(
        RewardDelivery $delivery,
        RewardDeliveryReconciler $reconciler,
    ): RedirectResponse {
        $delivery = $reconciler->reconcile($delivery);

        $message = match ($delivery->status) {
            RewardDelivery::STATUS_QUEUED => __('rewards.queue.reconcile.queued'),
            RewardDelivery::STATUS_FAILED => __('rewards.queue.reconcile.failed'),
            default => __('rewards.queue.reconcile.review'),
        };

        return redirect()
            ->route('admin.rewards.index')
            ->with('status', $message);
    }
}
