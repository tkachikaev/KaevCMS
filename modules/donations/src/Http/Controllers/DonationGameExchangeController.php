<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Exceptions\WalletOperationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GameAssets\GameItemCatalog;
use App\Services\Wallet\WalletAccountReadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationGameExchange;

final class DonationGameExchangeController extends Controller
{
    public function index(Request $request, DonationGameExchange $exchange, WalletAccountReadService $wallets, GameItemCatalog $items): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $servers = $exchange->available();
        foreach ($servers as &$server) {
            $server['item'] = $items->findForServer($server['server_id'], $server['item_id']);
        }
        unset($server);

        /** @var view-string $view */
        $view = $request->boolean('dialog') ? 'module-donations::account.exchange-form' : 'module-donations::account.exchange';

        return view($view, [
            'user' => $user, 'walletView' => $wallets->forUser($user),
            'servers' => $servers, 'requestUuid' => (string) Str::uuid(),
            'maxCoins' => DonationGameExchange::MAX_COINS,
            'maxGameItems' => DonationGameExchange::MAX_GAME_ITEMS,
            'successInventoryUrl' => session('exchange_inventory_url'),
        ]);
    }

    public function store(Request $request, DonationGameExchange $exchange,
        WalletAccountReadService $wallets, GameItemCatalog $items): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $values = $request->validate([
            'server_id' => ['required', 'integer', 'min:1'],
            'coins' => ['required', 'integer', 'min:1', 'max:'.DonationGameExchange::MAX_COINS],
            'request_uuid' => ['required', 'uuid'],
        ]);
        try {
            $result = $exchange->exchange($user, (int) $values['server_id'], (int) $values['coins'], (string) $values['request_uuid']);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (InvalidArgumentException|WalletOperationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('module-donations::messages.exchange_failed')], 422);
            }

            return back()->withInput()->withErrors(['exchange' => __('module-donations::messages.exchange_failed')]);
        }

        $inventoryUrl = public_route('web-inventory.index', ['server' => $result['server_id']]);
        if ($request->expectsJson()) {
            $item = $items->findForServer($result['server_id'], $result['item_id']);

            return response()->json([
                'message' => __('module-donations::messages.exchange_success_title'),
                'received' => $item['name'].' × '.number_format($result['game_amount'], 0, '.', ' '),
                'inventory_url' => $inventoryUrl,
                'wallet' => $wallets->forUser($user),
            ]);
        }

        return redirect()->route('modules.donations.exchange.index')
            ->with('exchange_inventory_url', $inventoryUrl)
            ->with('status', __('module-donations::messages.exchange_success', ['amount' => $result['game_amount']]));
    }
}
