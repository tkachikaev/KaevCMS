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
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopSetting;
use KaevCMS\Modules\Shop\Models\ShopTab;
use RuntimeException;

final class AdminShopController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $servers = GameServer::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get();
        $tabCounts = ShopTab::query()->selectRaw('game_server_id, COUNT(*) as total')
            ->groupBy('game_server_id')->pluck('total', 'game_server_id');

        return view('module-shop::admin.index', [
            'global' => ShopSetting::query()->where('setting_key', 'global')->firstOrFail(),
            'servers' => $servers,
            'tabCounts' => $tabCounts,
            'serverCount' => $servers->count(),
            'configuredCount' => $tabCounts->count(),
        ]);
    }

    public function server(Request $request, GameServer $server): View
    {
        $server->load('translations');
        $tabs = ShopTab::query()->where('game_server_id', $server->id)
            ->with('categories')->orderBy('sort_order')->orderBy('id')->get();

        return view('module-shop::admin.server', [
            'server' => $server,
            'tabs' => $tabs,
            'products' => ShopProduct::query()->whereHas('category.tab', fn ($query) => $query->where('game_server_id', $server->id))
                ->with(['category.tab', 'bundleItems'])->orderBy('sort_order')->orderBy('id')->get(),
            'canManage' => $this->canManage($request),
        ]);
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
            if ($tab->categories()->exists()) {
                throw ValidationException::withMessages(['confirm' => __('module-shop::messages.tab_has_categories')]);
            }
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
