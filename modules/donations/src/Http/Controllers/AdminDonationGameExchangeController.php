<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\GameServer;
use App\Services\Admin\AdminPathSettings;
use App\Services\GameAssets\GameItemCatalog;
use App\Services\Wallet\WalletAppearanceSettings;
use App\Support\Modules\ModuleAdminAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationGameExchange;

final class AdminDonationGameExchangeController extends Controller
{
    public function index(DonationGameExchange $exchange, AdminPathSettings $paths,
        GameItemCatalog $items, WalletAppearanceSettings $appearance): View
    {
        $servers = [];
        foreach ($exchange->configured() as $index => $server) {
            $itemId = (int) old('servers.'.$index.'.item_id', $server['item_id'] === null ? null : (string) $server['item_id']);
            $servers[] = $server + ['preview' => $itemId > 0 && $itemId <= 2147483647
                ? $items->findForServer($server['server_id'], $itemId) : null];
        }

        /** @var view-string $view */
        $view = 'module-donations::admin.exchange';

        return view($view, [
            'servers' => $servers, 'adminPath' => $paths->path(),
            'walletAppearance' => $appearance->values(), 'coinLabel' => $appearance->label(1),
            'readOnly' => (bool) request()->attributes->get('admin_read_only'),
        ]);
    }

    public function itemPreview(int $serverId, int $itemId, GameItemCatalog $items): JsonResponse
    {
        abort_unless($itemId > 0 && $itemId <= 2147483647, 404);
        $item = $items->findForServer(GameServer::query()->findOrFail($serverId), $itemId);

        return response()->json(['item_id' => $item['id'], 'name' => $item['name'], 'icon_url' => $item['icon']]);
    }

    public function save(Request $request, DonationGameExchange $exchange,
        ModuleAdminAuthorizer $access, AdminPathSettings $paths): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.exchange.save', 'PUT', $admin);
        $values = $request->validate([
            'servers' => ['required', 'array', 'min:1', 'max:1000'],
            'servers.*' => ['required', 'array:server_id,item_id,items_per_coin,enabled'],
            'servers.*.server_id' => ['required', 'integer', 'distinct', 'exists:game_servers,id'],
            'servers.*.enabled' => ['required', 'boolean'],
            'servers.*.item_id' => ['nullable', 'required_if:servers.*.enabled,1', 'integer', 'min:1', 'max:2147483647'],
            'servers.*.items_per_coin' => ['nullable', 'required_if:servers.*.enabled,1', 'integer', 'min:1', 'max:1000000'],
        ], $this->validationMessages('servers.*.'), $this->validationAttributes($request));

        try {
            $servers = array_column($values['servers'], null, 'server_id');
            ksort($servers, SORT_NUMERIC);
            DB::transaction(function () use ($servers, $admin, $exchange): void {
                foreach ($servers as $server) {
                    $exchange->configure($admin, (int) $server['server_id'],
                        isset($server['item_id']) ? (int) $server['item_id'] : null,
                        isset($server['items_per_coin']) ? (int) $server['items_per_coin'] : null,
                        (bool) $server['enabled']);
                }
            }, 3);
        } catch (InvalidArgumentException) {
            return back()->withInput()->withErrors(['exchange' => __('module-donations::messages.exchange_settings_invalid')]);
        }

        return redirect()->route('admin.module-pages.donations.exchange.index', ['adminPath' => $paths->path()])
            ->with('status', __('module-donations::messages.exchange_settings_saved'));
    }

    public function update(Request $request, int $serverId, DonationGameExchange $exchange,
        ModuleAdminAuthorizer $access, AdminPathSettings $paths): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.exchange.update', 'PUT', $admin);
        $values = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'item_id' => ['nullable', 'required_if:enabled,1', 'integer', 'min:1', 'max:2147483647'],
            'items_per_coin' => ['nullable', 'required_if:enabled,1', 'integer', 'min:1', 'max:1000000'],
        ], $this->validationMessages(), $this->validationAttributes($request, $serverId));
        try {
            $exchange->configure($admin, $serverId,
                isset($values['item_id']) ? (int) $values['item_id'] : null,
                isset($values['items_per_coin']) ? (int) $values['items_per_coin'] : null,
                (bool) ($values['enabled'] ?? false));
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['exchange' => __('module-donations::messages.exchange_settings_invalid')]);
        }

        return redirect()->route('admin.module-pages.donations.exchange.index', ['adminPath' => $paths->path()])
            ->with('status', __('module-donations::messages.exchange_settings_saved'));
    }

    /** @return array<string, string> */
    private function validationMessages(string $prefix = ''): array
    {
        $messages = [
            'required_if' => __('module-donations::messages.exchange_validation_required'),
            'integer' => __('module-donations::messages.exchange_validation_integer'),
            'array' => __('module-donations::messages.exchange_validation_format'),
            'exists' => __('module-donations::messages.exchange_validation_server_unavailable'),
            'distinct' => __('module-donations::messages.exchange_validation_server_duplicate'),
        ];
        foreach (['item_id', 'items_per_coin'] as $field) {
            foreach (['min', 'max'] as $rule) {
                $messages[$prefix.$field.'.'.$rule] = __('module-donations::messages.exchange_validation_'.$rule);
            }
        }

        return $messages;
    }

    /** @return array<string, string> */
    private function validationAttributes(Request $request, ?int $serverId = null): array
    {
        $labels = [
            'server_id' => __('module-donations::messages.exchange_server'),
            'enabled' => __('module-donations::messages.exchange_enabled_short'),
            'item_id' => __('module-donations::messages.exchange_item_id'),
            'items_per_coin' => __('module-donations::messages.exchange_quantity'),
        ];
        $attributes = [
            'servers' => __('module-donations::messages.exchange_validation_servers'),
            'servers.*' => __('module-donations::messages.exchange_validation_server_settings'),
        ];
        foreach ($labels as $field => $label) {
            $attributes[$serverId === null ? 'servers.*.'.$field : $field] = $label;
        }
        $rows = $serverId === null ? $request->input('servers') : [['server_id' => $serverId]];
        if (! is_array($rows) || count($rows) > 1000) {
            return $attributes;
        }
        // Names come from the CMS, not from submitted labels or the row's position.
        $names = GameServer::query()->pluck('name', 'id');
        foreach ($rows as $index => $row) {
            $id = is_array($row) ? ($row['server_id'] ?? null) : null;
            if ((! is_int($id) && ! is_string($id)) || ! is_string($name = $names->get($id))) {
                continue;
            }
            foreach ($labels as $field => $label) {
                $attributes[$serverId === null ? 'servers.'.$index.'.'.$field : $field] = __('module-donations::messages.exchange_validation_field', [
                    'server' => $name, 'field' => $label,
                ]);
            }
        }

        return $attributes;
    }
}
