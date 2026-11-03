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
use KaevCMS\Modules\Shop\Services\ShopProductArtwork;
use KaevCMS\Modules\Shop\Services\ShopPurchaseService;

final class ShopAccountController extends Controller
{
    public function index(Request $request, WalletAccountReadService $wallets, ShopProductArtwork $artwork): View
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active, 403);

        // Only complete products of shown sections reach the player. A legacy
        // product must still honor its hidden category.
        $visibleProducts = static fn ($query) => $query->visibleInCatalog();
        $serverIds = ShopTab::query()->where('is_active', true)
            ->whereHas('products', $visibleProducts)->pluck('game_server_id')->unique();
        $servers = GameServer::query()->with('translations')->whereIn('id', $serverIds)
            ->orderBy('sort_order')->orderBy('id')->get();
        $requestedServer = filter_var($request->query('server'), FILTER_VALIDATE_INT);
        $server = $servers->firstWhere('id', $requestedServer) ?? $servers->first();

        $tabs = collect();
        $products = null;
        $tab = null;
        $productVisuals = [];
        $requestUuids = [];
        $canBuy = false;

        if ($server !== null) {
            $tabs = ShopTab::query()->where('game_server_id', $server->id)->where('is_active', true)
                ->whereHas('products', $visibleProducts)->with('translations')
                ->orderBy('sort_order')->orderBy('id')->get();
            $requestedTab = filter_var($request->query('tab'), FILTER_VALIDATE_INT);
            $tab = $tabs->firstWhere('id', $requestedTab) ?? $tabs->first();
            if ($tab !== null) {
                $products = ShopProduct::query()->where('section_id', $tab->id)->visibleInCatalog()
                    ->with(['bundleItems', 'translations'])->orderBy('sort_order')->orderBy('id')
                    ->paginate(12)->appends(['server' => $server->id, 'tab' => $tab->id]);
                $productVisuals = $artwork->forProducts($server, $products);
                foreach ($products as $product) {
                    $requestUuids[$product->id] = (string) Str::uuid();
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
            'productVisuals' => $productVisuals, 'requestUuids' => $requestUuids, 'canBuy' => $canBuy,
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

    public function showPurchase(Request $request, int $purchase, GameItemCatalog $catalog): View
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active, 403);

        $record = ShopPurchase::query()->whereKey($purchase)
            ->where('user_id', $user->id)->with('gameServer.translations')->firstOrFail();

        return view('module-shop::account.receipt', [
            'user' => $user, 'purchase' => $record,
            'itemDetails' => $this->itemDetails($catalog, $record),
        ]);
    }

    public function store(Request $request, ShopPurchaseService $purchases, GameItemCatalog $catalog): RedirectResponse
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
            return back()->withInput()->withErrors(['purchase' => __('module-shop::messages.purchase_failed')])
                ->with('account_operation', [
                    'type' => 'error',
                    'eyebrow' => __('module-shop::messages.account_title'),
                    'title' => __('module-shop::messages.purchase_failed_title'),
                    'message' => __('module-shop::messages.purchase_failed'),
                ]);
        }

        $purchase->loadMissing('gameServer');
        $items = [];
        foreach ($this->itemDetails($catalog, $purchase) as $item) {
            $items[] = ['name' => $item['name'], 'amount' => $item['quantity'], 'icon_url' => $item['icon']];
        }

        return redirect()->route('modules.shop.index', array_filter([
            'server' => $purchase->game_server_id,
            'tab' => filter_var($request->input('return_tab'), FILTER_VALIDATE_INT) ?: null,
        ]))->with('shop_purchased', true)->with('account_operation', [
            'type' => 'success',
            'eyebrow' => __('module-shop::messages.account_title'),
            'title' => __('module-shop::messages.purchase_success_title', ['product' => $purchase->nameFor()]),
            'message' => __('module-shop::messages.purchase_success'),
            'items' => $items,
            'action_url' => public_route('web-inventory.index', ['server' => $purchase->game_server_id]),
            'action_label' => __('module-shop::messages.open_inventory'),
        ]);
    }

    /** @return list<array{item_id:int, quantity:int, name:string, icon:?string}> */
    private function itemDetails(GameItemCatalog $catalog, ShopPurchase $purchase): array
    {
        $details = [];
        foreach ($purchase->items_snapshot as $row) {
            $itemId = $row['item_id'];
            if ($itemId < 1) {
                continue;
            }
            $item = $catalog->findForServer($purchase->gameServer ?? $purchase->game_server_id, $itemId);
            $details[] = ['item_id' => $itemId, 'quantity' => $row['quantity'],
                'name' => $item['name'], 'icon' => $item['icon']];
        }

        return $details;
    }
}
