<?php

namespace KaevCMS\Modules\Shop\Http\Controllers;

use App\Exceptions\WalletOperationException;
use App\Http\Controllers\Controller;
use App\Models\GameServer;
use App\Models\User;
use App\Services\GameAssets\GameItemCatalog;
use App\Services\Wallet\WalletAccountReadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopPurchase;
use KaevCMS\Modules\Shop\Models\ShopTab;
use KaevCMS\Modules\Shop\Services\ShopPurchaseService;

final class ShopAccountController extends Controller
{
    public function index(Request $request, WalletAccountReadService $wallets, GameItemCatalog $items): View
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active, 403);

        // A legacy product must still honor its hidden category. New products
        // have no category and are published directly inside a section.
        $visibleProducts = static fn ($query) => $query->published()->where(static fn ($visibility) => $visibility
            ->whereNull('category_id')
            ->orWhereHas('category', static fn ($legacy) => $legacy->where('is_active', true)));
        $serverIds = ShopTab::query()->where('is_active', true)
            ->whereHas('products', $visibleProducts)->pluck('game_server_id')->unique();
        $servers = GameServer::query()->with('translations')->whereIn('id', $serverIds)
            ->orderBy('sort_order')->orderBy('id')->get();
        $requestedServer = filter_var($request->query('server'), FILTER_VALIDATE_INT);
        $server = $servers->firstWhere('id', $requestedServer) ?? $servers->first();

        $tabs = collect();
        $products = null;
        $tab = null;
        $productIcons = [];
        $requestUuids = [];
        $canBuy = false;

        if ($server !== null) {
            $tabs = ShopTab::query()->where('game_server_id', $server->id)->where('is_active', true)
                ->whereHas('products', $visibleProducts)->orderBy('sort_order')->orderBy('id')->get();
            $requestedTab = filter_var($request->query('tab'), FILTER_VALIDATE_INT);
            $tab = $tabs->firstWhere('id', $requestedTab) ?? $tabs->first();
            if ($tab !== null) {
                $products = ShopProduct::query()->where('section_id', $tab->id)
                    ->where(static fn ($query) => $query->whereNull('category_id')
                        ->orWhereHas('category', static fn ($legacy) => $legacy->where('is_active', true)))
                    ->published()->with('bundleItems')->orderBy('sort_order')->orderBy('id')
                    ->paginate(12)->appends(['server' => $server->id, 'tab' => $tab->id]);
                foreach ($products as $product) {
                    $requestUuids[$product->id] = (string) Str::uuid();
                    $firstItemId = $product->type === 'item' ? $product->item_id : $product->bundleItems->first()?->item_id;
                    if ($firstItemId !== null) {
                        $productIcons[$product->id] = $items->findForServer($server, (int) $firstItemId)['icon'];
                    }
                }
            }
            // The trusted adapter alone never enables sales.
            $canBuy = config('wallet.enabled_sources.shop') === true
                && DB::table('module_shop_settings')->where('setting_key', 'global')->where('sales_enabled', true)->exists()
                && DB::table('module_shop_server_settings')->where('game_server_id', $server->id)->where('enabled', true)->exists();
        }

        return view('module-shop::account.index', [
            'user' => $user,
            'servers' => $servers, 'server' => $server, 'tabs' => $tabs, 'tab' => $tab,
            'products' => $products,
            'productIcons' => $productIcons, 'requestUuids' => $requestUuids, 'canBuy' => $canBuy,
            'walletView' => $wallets->forUser($user),
        ]);
    }

    public function history(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active, 403);

        // History is independent of catalog visibility and sales switches. Only the
        // authenticated owner's completed, immutable purchase snapshots are shown.
        $purchases = ShopPurchase::query()->where('user_id', $user->id)
            ->with('gameServer.translations')->orderByDesc('id')->paginate(15);

        return view('module-shop::account.history', ['user' => $user, 'purchases' => $purchases]);
    }

    public function showPurchase(Request $request, int $purchase): View
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active, 403);

        $record = ShopPurchase::query()->whereKey($purchase)
            ->where('user_id', $user->id)->with('gameServer.translations')->firstOrFail();

        return view('module-shop::account.receipt', ['user' => $user, 'purchase' => $record]);
    }

    public function store(Request $request, ShopPurchaseService $purchases): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active, 403);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'request_uuid' => ['required', 'uuid'],
        ]);

        try {
            $purchase = $purchases->purchase($user, (int) $data['product_id'], (string) $data['request_uuid']);
        } catch (AuthorizationException $exception) {
            // Fail closed, even for manually constructed requests while sales are off.
            throw $exception;
        } catch (WalletOperationException|InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['purchase' => __('module-shop::messages.purchase_failed')]);
        }

        return redirect()->route('modules.shop.index', ['server' => $purchase->game_server_id])
            ->with('shop_purchased', true);
    }
}
