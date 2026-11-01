<?php

namespace KaevCMS\Modules\Shop\Http\Controllers;

use App\Auth\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KaevCMS\Modules\Shop\Models\ShopBundleItem;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopTab;
use RuntimeException;

final class AdminShopProductController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Request $request, GameServer $server): View
    {
        $type = $request->query('type', 'item');
        abort_unless(in_array($type, ['item', 'bundle'], true), 404);

        return view('module-shop::admin.product', $this->viewData($request, $server, null, $type));
    }

    public function edit(Request $request, GameServer $server, ShopProduct $product): View
    {
        $this->assertProduct($server, $product);
        $product->load('bundleItems');

        return view('module-shop::admin.product', $this->viewData($request, $server, $product, $product->type));
    }

    public function store(Request $request, GameServer $server): RedirectResponse
    {
        $admin = $this->admin($request);
        $type = $request->validate(['type' => ['required', Rule::in(['item', 'bundle'])]])['type'];
        $values = $this->validateProduct($request, $server, $type);
        $product = DB::transaction(function () use ($admin, $values, $type, $server): ShopProduct {
            $product = ShopProduct::query()->create($values + [
                'type' => $type,
                'updated_by_admin_id' => $admin->id,
            ]);
            $this->auditRequired('shop.product_created', $admin, $product, [
                'game_server_id' => $server->id, 'type' => $type, 'price_coins' => $product->price_coins,
            ]);

            return $product;
        }, 3);

        return $this->back('products.edit', ['server' => $server->id, 'product' => $product->id])
            ->with('status', __('module-shop::messages.product_saved'));
    }

    public function update(Request $request, GameServer $server, ShopProduct $product): RedirectResponse
    {
        $this->assertProduct($server, $product);
        $admin = $this->admin($request);
        $values = $this->validateProduct($request, $server, $product->type);
        DB::transaction(function () use ($admin, $product, $values, $server): void {
            $locked = ShopProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertProduct($server, $locked);
            $before = $locked->only(array_keys($values));
            $locked->update($values + ['updated_by_admin_id' => $admin->id]);
            $this->auditRequired('shop.product_updated', $admin, $locked, [
                'game_server_id' => $server->id, 'before' => $before,
                'after' => $locked->only(array_keys($values)),
            ]);
        }, 3);

        return $this->back('products.edit', ['server' => $server->id, 'product' => $product->id])
            ->with('status', __('module-shop::messages.product_saved'));
    }

    public function destroy(Request $request, GameServer $server, ShopProduct $product): RedirectResponse
    {
        $this->assertProduct($server, $product);
        $admin = $this->admin($request);
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($admin, $product, $server): void {
            $locked = ShopProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertProduct($server, $locked);
            $id = $locked->id;
            $locked->delete(); // Purchase history retains its own immutable product ID and snapshot.
            $this->auditRequired('shop.product_deleted', $admin, 'shop_product:'.$id, [
                'game_server_id' => $server->id,
            ]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])
            ->with('status', __('module-shop::messages.product_deleted'));
    }

    public function addBundleItem(Request $request, GameServer $server, ShopProduct $product): RedirectResponse
    {
        $this->assertBundle($server, $product);
        $admin = $this->admin($request);
        $values = $request->validate([
            'item_id' => ['required', 'integer', 'between:1,2147483647'],
            'quantity' => ['required', 'integer', 'between:1,2000000000'],
        ]);
        DB::transaction(function () use ($admin, $server, $product, $values): void {
            $locked = ShopProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertBundle($server, $locked);
            if ($locked->bundleItems()->where('item_id', $values['item_id'])->exists()) {
                throw ValidationException::withMessages(['item_id' => __('module-shop::messages.duplicate_bundle_item')]);
            }
            // Checkout grants at most 100 items. Enforce the same limit at
            // configuration time while holding the product lock.
            if ($locked->bundleItems()->count() >= 100) {
                throw ValidationException::withMessages(['item_id' => __('module-shop::messages.bundle_item_limit_reached')]);
            }
            $item = $locked->bundleItems()->create($values);
            $this->auditRequired('shop.bundle_item_added', $admin, $item, [
                'game_server_id' => $server->id, 'product_id' => $locked->id,
                'item_id' => $item->item_id, 'quantity' => $item->quantity,
            ]);
        }, 3);

        return $this->back('products.edit', ['server' => $server->id, 'product' => $product->id])
            ->with('status', __('module-shop::messages.bundle_item_added'));
    }

    public function removeBundleItem(Request $request, GameServer $server, ShopProduct $product, ShopBundleItem $bundleItem): RedirectResponse
    {
        $this->assertBundle($server, $product);
        abort_unless($bundleItem->product_id === $product->id, 404);
        $admin = $this->admin($request);
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($admin, $server, $product, $bundleItem): void {
            $locked = ShopProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertBundle($server, $locked);
            $item = $locked->bundleItems()->whereKey($bundleItem->id)->firstOrFail();
            $details = ['game_server_id' => $server->id, 'product_id' => $locked->id,
                'item_id' => $item->item_id, 'quantity' => $item->quantity];
            $itemId = $item->id;
            $item->delete();
            $this->auditRequired('shop.bundle_item_removed', $admin, 'shop_bundle_item:'.$itemId, $details);
        }, 3);

        return $this->back('products.edit', ['server' => $server->id, 'product' => $product->id])
            ->with('status', __('module-shop::messages.bundle_item_removed'));
    }

    /** @return array<string, mixed> */
    private function viewData(Request $request, GameServer $server, ?ShopProduct $product, string $type): array
    {
        $server->load('translations');
        $tabs = ShopTab::query()->where('game_server_id', $server->id)
            ->with('categories')->orderBy('sort_order')->orderBy('id')->get();
        // The category select never contains entries belonging to another server.
        $categoryOptions = [];
        foreach ($tabs as $tab) {
            foreach ($tab->categories as $category) {
                $categoryOptions[] = ['id' => $category->id, 'name' => $tab->nameFor().' / '.$category->nameFor()];
            }
        }
        $categories = collect($categoryOptions);

        return [
            'server' => $server, 'product' => $product, 'type' => $type,
            'categories' => $categories, 'canManage' => $this->canManage($request),
        ];
    }

    /** @return array<string, mixed> */
    private function validateProduct(Request $request, GameServer $server, string $type): array
    {
        $rules = [
            'category_id' => ['required', 'integer', 'exists:module_shop_categories,id'],
            'name_ru' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'price_coins' => ['required', 'integer', 'between:1,1000000'],
            'sort_order' => ['required', 'integer', 'between:0,10000'],
            'is_active' => ['required', 'boolean'],
        ];
        if ($type === 'item') {
            $rules['item_id'] = ['required', 'integer', 'between:1,2147483647'];
            $rules['quantity'] = ['required', 'integer', 'between:1,2000000000'];
        }
        $values = $request->validate($rules);
        if (! ShopCategory::query()->whereKey($values['category_id'])
            ->whereHas('tab', fn ($query) => $query->where('game_server_id', $server->id))->exists()) {
            throw ValidationException::withMessages(['category_id' => __('module-shop::messages.category_wrong_server')]);
        }
        $values['name_ru'] = trim($values['name_ru']);
        $values['name_en'] = trim($values['name_en']);
        if ($values['name_ru'] === '' || $values['name_en'] === '') {
            throw ValidationException::withMessages(['name_ru' => __('module-shop::messages.names_required')]);
        }
        if ($type === 'bundle') {
            $values['item_id'] = null;
            $values['quantity'] = null;
        }

        return $values;
    }

    private function assertProduct(GameServer $server, ShopProduct $product): void
    {
        abort_unless($product->category()->whereHas('tab', fn ($query) => $query->where('game_server_id', $server->id))->exists(), 404);
    }

    private function assertBundle(GameServer $server, ShopProduct $product): void
    {
        $this->assertProduct($server, $product);
        abort_unless($product->type === 'bundle', 404);
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $this->canManage($request), 403);

        return $admin;
    }

    private function canManage(Request $request): bool
    {
        $admin = $request->user('admin');

        return $admin instanceof Admin && in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true);
    }

    /** @param array<string, mixed> $details */
    private function auditRequired(string $action, Admin $admin, ShopProduct|ShopBundleItem|string $target, array $details): void
    {
        if ($this->audit->success(category: 'module', action: $action, actor: $admin, target: $target, details: $details) === null) {
            throw new RuntimeException('Shop product audit could not be recorded.');
        }
    }

    /** @param array<string, int> $parameters */
    private function back(string $route, array $parameters = []): RedirectResponse
    {
        return redirect()->route('admin.module-pages.shop.'.$route, array_merge(['adminPath' => request()->route('adminPath')], $parameters));
    }
}
