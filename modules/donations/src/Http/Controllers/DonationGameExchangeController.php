<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Exceptions\WalletOperationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Wallet\WalletAccountReadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationGameExchange;

final class DonationGameExchangeController extends Controller
{
    public function index(Request $request, DonationGameExchange $exchange, WalletAccountReadService $wallets): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $servers = $exchange->available();

        return view('module-donations::account.exchange', [
            'user' => $user, 'walletView' => $wallets->forUser($user),
            'servers' => $servers, 'requestUuid' => (string) Str::uuid(),
            'maxCoins' => DonationGameExchange::MAX_COINS,
            'maxGameItems' => DonationGameExchange::MAX_GAME_ITEMS,
            'history' => DB::table('module_donation_game_exchanges as e')
                ->join('game_servers as s', 's.id', '=', 'e.game_server_id')
                ->where('e.user_id', $user->getKey())->orderByDesc('e.id')->limit(15)
                ->get(['e.coins', 'e.game_amount', 'e.item_id', 'e.created_at', 's.name as server_name']),
        ]);
    }

    public function store(Request $request, DonationGameExchange $exchange): RedirectResponse
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
            return back()->withInput()->withErrors(['exchange' => __('module-donations::messages.exchange_failed')]);
        }

        return redirect()->route('modules.donations.exchange.index')
            ->with('status', __('module-donations::messages.exchange_success', ['amount' => $result['game_amount']]));
    }
}
