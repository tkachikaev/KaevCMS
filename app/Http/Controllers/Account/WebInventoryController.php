<?php

namespace App\Http\Controllers\Account;

use App\Contracts\GameRewardQueueGateway;
use App\Http\Controllers\Controller;
use App\Models\GameServer;
use App\Models\RewardDelivery;
use App\Models\RewardInventoryItem;
use App\Models\User;
use App\Services\GameAssets\GameAssetUrlResolver;
use App\Services\Rewards\RewardCharacterDirectory;
use App\Support\Rewards\RewardQueueCapabilities;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WebInventoryController extends Controller
{
    public function __invoke(
        Request $request,
        GameRewardQueueGateway $rewardQueue,
        RewardCharacterDirectory $characters,
        GameAssetUrlResolver $assets,
    ): View {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $serverIds = RewardInventoryItem::query()
            ->where('user_id', $user->id)
            ->pluck('game_server_id')
            ->merge(RewardDelivery::query()->where('user_id', $user->id)->pluck('game_server_id'))
            ->unique()
            ->values();

        /** @var Collection<int,GameServer> $servers */
        $servers = GameServer::query()
            ->with('translations')
            ->whereIn('id', $serverIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $requestedServerId = max(0, (int) $request->query('server'));
        $selectedServer = $servers->firstWhere('id', $requestedServerId) ?? $servers->first();
        $activeView = $request->query('view') === 'history' ? 'history' : 'available';

        $availableItems = collect();
        $deliveries = RewardDelivery::query()->whereRaw('1 = 0')->paginate(20);
        $characterRows = [];
        $itemIconUrls = [];
        $deliveryItemIconUrls = [];
        $capabilities = RewardQueueCapabilities::unsupported('reward_queue_not_installed');

        if ($selectedServer instanceof GameServer) {
            $availableItems = RewardInventoryItem::query()
                ->with('grant')
                ->where('user_id', $user->id)
                ->where('game_server_id', $selectedServer->id)
                ->where('status', RewardInventoryItem::STATUS_AVAILABLE)
                ->latest('id')
                ->get();

            foreach ($availableItems as $item) {
                $itemIconUrls[$item->id] = $assets->itemIcon($selectedServer, $item->item_id);
            }

            $deliveries = RewardDelivery::query()
                ->with(['items', 'gameServer.translations'])
                ->where('user_id', $user->id)
                ->where('game_server_id', $selectedServer->id)
                ->latest('id')
                ->paginate(20)
                ->withQueryString();

            foreach ($deliveries as $delivery) {
                foreach ($delivery->items as $item) {
                    $deliveryItemIconUrls[$item->id] = $assets->itemIcon($selectedServer, $item->item_id);
                }
            }

            $capabilities = $rewardQueue->capabilities($selectedServer);
            if ($capabilities->supported) {
                $characterRows = $characters->forServer($user, $selectedServer);
            }
        }

        return view('account-theme::web-inventory.index', [
            'user' => $user,
            'servers' => $servers,
            'selectedServer' => $selectedServer,
            'activeView' => $activeView,
            'availableItems' => $availableItems,
            'itemIconUrls' => $itemIconUrls,
            'deliveryItemIconUrls' => $deliveryItemIconUrls,
            'deliveries' => $deliveries,
            'characters' => $characterRows,
            'capabilities' => $capabilities,
            'deliveryUnavailableMessage' => $this->deliveryUnavailableMessage($capabilities),
            'requestToken' => (string) Str::uuid(),
        ]);
    }

    private function deliveryUnavailableMessage(RewardQueueCapabilities $capabilities): string
    {
        return match ($capabilities->reasonCode) {
            'reward_queue_not_installed' => __('The kaev_reward_queue table is not installed in this GameServer database.'),
            'reward_queue_schema_invalid' => __('The kaev_reward_queue table has an unsupported structure.'),
            default => __('The GameServer reward queue is unavailable. Check the database connection.'),
        };
    }
}
