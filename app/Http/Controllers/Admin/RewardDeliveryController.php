<?php

namespace App\Http\Controllers\Admin;

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
    public function index(Request $request, GameAssetUrlResolver $assets): View
    {
        $status = strtolower(trim((string) $request->query('status')));
        if (! in_array($status, [
            RewardDelivery::STATUS_PENDING,
            RewardDelivery::STATUS_QUEUED,
            RewardDelivery::STATUS_FAILED,
            RewardDelivery::STATUS_REVIEW,
        ], true)) {
            $status = null;
        }

        $serverId = max(0, (int) $request->query('server'));
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

        return view('admin.rewards.index', [
            'deliveries' => $deliveries,
            'itemIconUrls' => $itemIconUrls,
            'activeStatus' => $status,
            'activeServerId' => $serverId,
            'servers' => GameServer::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get(),
            'totalCount' => RewardDelivery::query()->count(),
        ]);
    }

    public function reconcile(
        RewardDelivery $delivery,
        RewardDeliveryReconciler $reconciler,
    ): RedirectResponse {
        $delivery = $reconciler->reconcile($delivery);

        $message = match ($delivery->status) {
            RewardDelivery::STATUS_QUEUED => __('The reward transfer was confirmed in the GameServer queue.'),
            RewardDelivery::STATUS_FAILED => __('The queue contains no matching operation. Reserved rewards were returned to the web inventory.'),
            default => __('The transfer result is still uncertain. The rewards remain reserved to prevent duplicate delivery.'),
        };

        return redirect()
            ->route('admin.rewards.index')
            ->with('status', $message);
    }
}
