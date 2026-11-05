<?php

namespace KaevCMS\Modules\Shop\Http\Controllers;

use App\Auth\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use App\Services\Localization\LanguageManager;
use App\Services\Wallet\WalletAppearanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopServerSetting;
use KaevCMS\Modules\Shop\Models\ShopSetting;
use KaevCMS\Modules\Shop\Models\ShopTab;
use KaevCMS\Modules\Shop\Services\ShopItemDirectory;
use KaevCMS\Modules\Shop\Services\ShopProductArtwork;
use KaevCMS\Modules\Shop\Services\ShopTranslationWriter;
use RuntimeException;

final class AdminShopController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View|RedirectResponse
    {
        $servers = GameServer::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get();
        // Everything is configured on one screen per game server.
        if ($servers->isNotEmpty()) {
            return $this->back('servers.show', ['server' => $servers->first()->id]);
        }
        $tabCounts = ShopTab::query()->selectRaw('game_server_id, COUNT(*) as total')
            ->groupBy('game_server_id')->pluck('total', 'game_server_id');

        return view('module-shop::admin.index', [
            'global' => ShopSetting::query()->where('setting_key', 'global')->firstOrFail(),
            'servers' => $servers,
            'tabCounts' => $tabCounts,
            'serverCount' => $servers->count(),
            'configuredCount' => $tabCounts->count(),
            'serverSettings' => ShopServerSetting::query()->get()->keyBy('game_server_id'),
            'canManage' => $this->canManage($request),
            'sourceEnabled' => config('wallet.enabled_sources.shop') === true,
        ]);
    }

    public function server(
        Request $request,
        GameServer $server,
        ShopProductArtwork $artwork,
        ShopTranslationWriter $texts,
        LanguageManager $languages,
        WalletAppearanceSettings $wallet,
    ): View {
        $server->load('translations');
        $tabs = ShopTab::query()->where('game_server_id', $server->id)->with('translations')
            ->withCount('products')->orderBy('sort_order')->orderBy('id')->get();

        $requestedSection = filter_var($request->query('section'), FILTER_VALIDATE_INT);
        $selectedSection = $tabs->firstWhere('id', $requestedSection) ?? $tabs->first();

        $products = $selectedSection === null ? collect() : ShopProduct::query()
            ->where('section_id', $selectedSection->id)
            ->with(['translations', 'category', 'bundleItems'])->orderBy('sort_order')->orderBy('id')->get();

        return view('module-shop::admin.server', [
            'server' => $server,
            'servers' => GameServer::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get(),
            'selectedSection' => $selectedSection,
            'tabs' => $tabs,
            'products' => $products,
            'productVisuals' => $artwork->forProducts($server, $products),
            'canManage' => $this->canManage($request),
            'settings' => ShopServerSetting::query()->where('game_server_id', $server->id)->first(),
            'salesEnabled' => (bool) ShopSetting::query()->where('setting_key', 'global')->value('sales_enabled'),
            'sourceEnabled' => config('wallet.enabled_sources.shop') === true,
            'languages' => array_intersect_key($languages->enabled(), array_flip($texts->locales())),
            'defaultLocale' => $texts->defaultLocale(),
            'coinIcon' => $wallet->values()['icon_url'],
        ]);
    }

    public function storeSection(Request $request, GameServer $server, ShopTranslationWriter $texts): RedirectResponse
    {
        $admin = $this->admin($request);
        $values = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $name = trim($values['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('module-shop::messages.name_required')]);
        }

        $tab = DB::transaction(function () use ($admin, $server, $name, $texts): ShopTab {
            GameServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $order = (int) ShopTab::query()->where('game_server_id', $server->id)->max('sort_order') + 1;
            $tab = ShopTab::query()->create([
                'game_server_id' => $server->id, 'name_ru' => $name, 'name_en' => $name,
                'sort_order' => min($order, 10000), 'is_active' => true, 'updated_by_admin_id' => $admin->id,
            ]);
            $texts->saveSection($tab, [$texts->defaultLocale() => $name]);
            $this->auditRequired('shop.tab_created', $admin, $tab, ['game_server_id' => $server->id]);

            return $tab;
        }, 3);

        return $this->back('servers.show', ['server' => $server->id, 'section' => $tab->id])
            ->with('status', __('module-shop::messages.section_created'));
    }

    public function updateSection(Request $request, GameServer $server, ShopTab $tab, ShopTranslationWriter $texts): RedirectResponse
    {
        $this->assertTab($server, $tab);
        $admin = $this->admin($request);
        $values = $request->validate([
            'names' => ['required', 'array'],
            'names.*' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ]);
        $names = array_intersect_key($values['names'], array_flip($texts->locales()));
        $legacy = $texts->legacyNames($names, 'names.'.$texts->defaultLocale());

        DB::transaction(function () use ($admin, $tab, $values, $names, $legacy, $texts): void {
            $locked = ShopTab::query()->lockForUpdate()->findOrFail($tab->id);
            $before = $locked->only(['name_ru', 'name_en', 'is_active']);
            $locked->update($legacy + ['is_active' => (bool) $values['is_active'], 'updated_by_admin_id' => $admin->id]);
            $texts->saveSection($locked, $names);
            $this->auditRequired('shop.tab_updated', $admin, $locked, ['before' => $before, 'after' => $locked->only(array_keys($before))]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id, 'section' => $tab->id])
            ->with('status', __('module-shop::messages.saved'));
    }

    public function sectionVisibility(Request $request, GameServer $server, ShopTab $tab): RedirectResponse
    {
        $this->assertTab($server, $tab);
        $admin = $this->admin($request);
        $values = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($admin, $tab, $values): void {
            $locked = ShopTab::query()->lockForUpdate()->findOrFail($tab->id);
            $before = ['is_active' => $locked->is_active];
            $locked->update(['is_active' => (bool) $values['is_active'], 'updated_by_admin_id' => $admin->id]);
            $this->auditRequired('shop.tab_updated', $admin, $locked, ['before' => $before, 'after' => ['is_active' => $locked->is_active]]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id, 'section' => $tab->id])
            ->with('status', __('module-shop::messages.saved'));
    }

    public function orderSections(Request $request, GameServer $server): JsonResponse
    {
        $admin = $this->admin($request);
        $ids = $this->validatedOrder($request);
        DB::transaction(function () use ($admin, $server, $ids): void {
            $sections = ShopTab::query()->where('game_server_id', $server->id)->lockForUpdate()->get()->keyBy('id');
            $this->assertCompleteOrder($ids, $sections->keys()->all());
            foreach ($ids as $position => $id) {
                $sections[$id]->update(['sort_order' => $position, 'updated_by_admin_id' => $admin->id]);
            }
            $this->auditRequired('shop.sections_reordered', $admin, 'shop_server:'.$server->id, ['order' => $ids]);
        }, 3);

        return response()->json(['saved' => true, 'message' => __('module-shop::messages.order_saved')]);
    }

    public function orderProducts(Request $request, GameServer $server, ShopTab $tab): JsonResponse
    {
        $this->assertTab($server, $tab);
        $admin = $this->admin($request);
        $ids = $this->validatedOrder($request);
        DB::transaction(function () use ($admin, $server, $tab, $ids): void {
            ShopTab::query()->lockForUpdate()->findOrFail($tab->id);
            $products = ShopProduct::query()->where('section_id', $tab->id)->lockForUpdate()->get()->keyBy('id');
            $this->assertCompleteOrder($ids, $products->keys()->all());
            foreach ($ids as $position => $id) {
                $products[$id]->update(['sort_order' => $position, 'updated_by_admin_id' => $admin->id]);
            }
            $this->auditRequired('shop.products_reordered', $admin, $tab, ['game_server_id' => $server->id, 'order' => $ids]);
        }, 3);

        return response()->json(['saved' => true, 'message' => __('module-shop::messages.order_saved')]);
    }

    public function itemSearch(Request $request, GameServer $server, ShopItemDirectory $items): JsonResponse
    {
        abort_unless($request->user('admin') instanceof Admin, 403);
        $query = $request->validate(['q' => ['required', 'string', 'max:80']])['q'];

        return response()->json(['items' => $items->search($server->loadMissing('translations'), $query)]);
    }

    public function itemPreview(Request $request, GameServer $server, int $item, ShopItemDirectory $items): JsonResponse
    {
        abort_unless($request->user('admin') instanceof Admin, 403);
        abort_unless($item > 0 && $item <= 2147483647, 404);

        return response()->json($items->describe($server->loadMissing('translations'), $item));
    }

    /** @return list<int> */
    private function validatedOrder(Request $request): array
    {
        $values = $request->validate(['ids' => ['required', 'array', 'max:1000'], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']]);

        return array_values(array_map('intval', $values['ids']));
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, int|string>  $existing
     */
    private function assertCompleteOrder(array $ids, array $existing): void
    {
        $expected = array_map('intval', $existing);
        sort($expected);
        $given = $ids;
        sort($given);
        if ($given !== $expected) {
            throw ValidationException::withMessages(['ids' => __('module-shop::messages.order_outdated')]);
        }
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $values = $request->validate(['sales_enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($admin, $values): void {
            $settings = ShopSetting::query()->where('setting_key', 'global')->lockForUpdate()->firstOrFail();
            $settings->update($values + ['updated_by_admin_id' => $admin->id]);
            $this->auditRequired('shop.settings_updated', $admin, 'shop_settings', $values);
        }, 3);

        $returnServer = filter_var($request->input('_return_server'), FILTER_VALIDATE_INT);
        if ($returnServer && GameServer::query()->whereKey($returnServer)->exists()) {
            return $this->back('servers.show', ['server' => $returnServer])->with('status', __('module-shop::messages.saved'));
        }

        return $this->back('index')->with('status', __('module-shop::messages.saved'));
    }

    public function updateServerSettings(Request $request, GameServer $server): RedirectResponse
    {
        $admin = $this->admin($request);
        $values = $request->validate(['enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($admin, $server, $values): void {
            GameServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            ShopServerSetting::query()->updateOrCreate(['game_server_id' => $server->id], $values + ['updated_by_admin_id' => $admin->id]);
            $this->auditRequired('shop.server_settings_updated', $admin, 'shop_server:'.$server->id, $values);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-shop::messages.saved'));
    }

    public function storeTab(Request $request, GameServer $server): RedirectResponse
    {
        $admin = $this->admin($request);
        $values = $this->validateSection($request);

        DB::transaction(function () use ($admin, $server, $values): void {
            $tab = ShopTab::query()->create($values + [
                'game_server_id' => $server->id,
                'updated_by_admin_id' => $admin->id,
            ]);
            // Legacy RU/EN form: keep translation rows in step with the columns.
            app(ShopTranslationWriter::class)->saveSection($tab, ['ru' => $tab->name_ru, 'en' => $tab->name_en]);
            $this->auditRequired('shop.tab_created', $admin, $tab, ['game_server_id' => $server->id]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-shop::messages.saved'));
    }

    public function editTab(Request $request, GameServer $server, ShopTab $tab): View
    {
        $this->assertTab($server, $tab);

        return view('module-shop::admin.edit', [
            'server' => $server,
            'record' => $tab,
            'kind' => 'tabs',
            'tabOptions' => collect(),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function updateTab(Request $request, GameServer $server, ShopTab $tab): RedirectResponse
    {
        $this->assertTab($server, $tab);
        $admin = $this->admin($request);
        $values = $this->validateSection($request);
        DB::transaction(function () use ($admin, $tab, $values): void {
            $before = $tab->only(['name_ru', 'name_en', 'sort_order', 'is_active']);
            $tab->update($values + ['updated_by_admin_id' => $admin->id]);
            app(ShopTranslationWriter::class)->saveSection($tab, ['ru' => $tab->name_ru, 'en' => $tab->name_en]);
            $this->auditRequired('shop.tab_updated', $admin, $tab, ['before' => $before, 'after' => $tab->only(array_keys($before))]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-shop::messages.saved'));
    }

    public function destroyTab(Request $request, GameServer $server, ShopTab $tab): RedirectResponse
    {
        $this->assertTab($server, $tab);
        $admin = $this->admin($request);
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($admin, $tab): void {
            $tab = ShopTab::query()->lockForUpdate()->findOrFail($tab->id);
            if ($tab->products()->exists()) {
                throw ValidationException::withMessages(['confirm' => __('module-shop::messages.section_has_products')]);
            }
            // Empty legacy categories may remain from earlier releases. They
            // are not user-facing and contain no products at this point.
            $tab->categories()->delete();
            $id = $tab->id;
            $serverId = $tab->game_server_id;
            $tab->delete();
            $this->auditRequired('shop.tab_deleted', $admin, 'shop_tab:'.$id, ['game_server_id' => $serverId]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-shop::messages.deleted'));
    }

    public function storeCategory(Request $request, GameServer $server): RedirectResponse
    {
        $admin = $this->admin($request);
        $values = $this->validateSection($request, $server);
        DB::transaction(function () use ($admin, $values, $server): void {
            $category = ShopCategory::query()->create($values + ['updated_by_admin_id' => $admin->id]);
            $this->auditRequired('shop.category_created', $admin, $category, ['game_server_id' => $server->id, 'tab_id' => $values['tab_id']]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-shop::messages.saved'));
    }

    public function editCategory(Request $request, GameServer $server, ShopCategory $category): View
    {
        $this->assertCategory($server, $category);

        return view('module-shop::admin.edit', [
            'server' => $server,
            'record' => $category,
            'kind' => 'categories',
            'tabOptions' => ShopTab::query()->where('game_server_id', $server->id)
                ->orderBy('sort_order')->orderBy('id')->get(),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function updateCategory(Request $request, GameServer $server, ShopCategory $category): RedirectResponse
    {
        $this->assertCategory($server, $category);
        $admin = $this->admin($request);
        $values = $this->validateSection($request, $server);
        DB::transaction(function () use ($admin, $category, $values, $server): void {
            $category = ShopCategory::query()->whereKey($category->id)
                ->whereHas('tab', fn ($query) => $query->where('game_server_id', $server->id))
                ->lockForUpdate()->firstOrFail();
            $before = $category->only(['tab_id', 'name_ru', 'name_en', 'sort_order', 'is_active']);
            $category->update($values + ['updated_by_admin_id' => $admin->id]);
            if ((int) $before['tab_id'] !== (int) $category->tab_id) {
                // Keep the direct section link in sync during the legacy UI
                // transition. Commit category, products and audit together.
                DB::table('module_shop_products')->where('category_id', $category->id)
                    ->update(['section_id' => $category->tab_id]);
            }
            $this->auditRequired('shop.category_updated', $admin, $category, ['before' => $before, 'after' => $category->only(array_keys($before))]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-shop::messages.saved'));
    }

    public function destroyCategory(Request $request, GameServer $server, ShopCategory $category): RedirectResponse
    {
        $this->assertCategory($server, $category);
        $admin = $this->admin($request);
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($admin, $category, $server): void {
            $category = ShopCategory::query()->whereKey($category->id)
                ->whereHas('tab', fn ($query) => $query->where('game_server_id', $server->id))
                ->lockForUpdate()->firstOrFail();
            if (ShopProduct::query()->where('category_id', $category->id)->exists()) {
                throw ValidationException::withMessages(['confirm' => __('module-shop::messages.category_has_products')]);
            }
            $id = $category->id;
            $category->delete();
            $this->auditRequired('shop.category_deleted', $admin, 'shop_category:'.$id, ['game_server_id' => $server->id]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-shop::messages.deleted'));
    }

    /** @return array<string, mixed> */
    private function validateSection(Request $request, ?GameServer $server = null): array
    {
        $rules = [
            'name_ru' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'sort_order' => ['required', 'integer', 'between:0,10000'],
            'is_active' => ['required', 'boolean'],
        ];
        if ($server !== null) {
            $rules['tab_id'] = ['required', 'integer', Rule::exists('module_shop_tabs', 'id')->where('game_server_id', $server->id)];
        }

        $values = $request->validate($rules);
        $values['name_ru'] = trim($values['name_ru']);
        $values['name_en'] = trim($values['name_en']);
        if ($values['name_ru'] === '' || $values['name_en'] === '') {
            throw ValidationException::withMessages(['name_ru' => __('module-shop::messages.names_required')]);
        }

        return $values;
    }

    private function assertTab(GameServer $server, ShopTab $tab): void
    {
        abort_unless($tab->game_server_id === $server->id, 404);
    }

    private function assertCategory(GameServer $server, ShopCategory $category): void
    {
        abort_unless($category->tab()->where('game_server_id', $server->id)->exists(), 404);
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
    private function auditRequired(string $action, Admin $admin, ShopTab|ShopCategory|string $target, array $details): void
    {
        if ($this->audit->success(category: 'module', action: $action, actor: $admin, target: $target, details: $details) === null) {
            throw new RuntimeException('Shop administrative audit could not be recorded.');
        }
    }

    /** @param array<string, int> $parameters */
    private function back(string $route, array $parameters = []): RedirectResponse
    {
        return redirect()->route('admin.module-pages.shop.'.$route, array_merge(['adminPath' => request()->route('adminPath')], $parameters));
    }
}
