<?php

namespace App\Http\Controllers\Account;

use App\Contracts\WalletTopUpHistory;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\WalletHistory;
use App\Services\Wallet\WalletAccountReadService;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Player-owned wallet history. No wallet IDs accepted from the browser. */
final class WalletController extends Controller
{
    public function __invoke(Request $request, WalletAccountReadService $wallets, ModuleNavigationRegistry $navigation, WalletHistory $history): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $topUpLink = collect($navigation->accountLinks())->firstWhere('route', 'modules.donations.index');

        return view('account-theme::wallet.index', [
            'user' => $user,
            'walletView' => $wallets->forUser($user),
            'entries' => $history->page($user, $request->boolean('inactive')),
            'includeInactive' => $request->boolean('inactive'),
            'canShowInactive' => app()->bound(WalletTopUpHistory::class),
            'topUpUrl' => $topUpLink !== null ? route($topUpLink['route']) : null,
            'walletLinks' => $navigation->walletLinks(),
        ]);
    }
}
