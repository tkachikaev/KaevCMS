<?php

namespace App\Http\Controllers\Account;

use App\Exceptions\RewardTransferException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\TransferRewardsRequest;
use App\Models\GameServer;
use App\Models\RewardDelivery;
use App\Models\User;
use App\Services\Rewards\RewardTransferService;
use Illuminate\Http\RedirectResponse;

class RewardTransferController extends Controller
{
    public function store(
        TransferRewardsRequest $request,
        RewardTransferService $transfers,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $server = GameServer::query()->findOrFail($request->integer('game_server_id'));

        try {
            $delivery = $transfers->queue(
                user: $user,
                server: $server,
                inventoryItemIds: array_map('intval', (array) $request->validated('inventory_item_ids')),
                characterId: $request->integer('character_id'),
                requestToken: (string) $request->validated('request_token'),
            );
        } catch (RewardTransferException $exception) {
            return back()
                ->withInput($request->except(['request_token']))
                ->withErrors(['inventory' => __($exception->messageKey())]);
        }

        $message = $delivery->status === RewardDelivery::STATUS_REVIEW
            ? __('The queue write result is uncertain. The rewards are locked for administrator review.')
            : __('Rewards were transferred to the GameServer queue.');

        return redirect()
            ->to(public_route('web-inventory.index', [
                'server' => $server->id,
                'view' => 'history',
            ]))
            ->with('status', $message);
    }
}
