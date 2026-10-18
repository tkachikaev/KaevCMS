<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminPathSettings;
use App\Support\Modules\ModuleAdminAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationGameExchange;

final class AdminDonationGameExchangeController extends Controller
{
    public function index(DonationGameExchange $exchange, AdminPathSettings $paths): View
    {
        /** @var view-string $view */
        $view = 'module-donations::admin.exchange';

        return view($view, [
            'servers' => $exchange->configured(), 'adminPath' => $paths->path(),
            'readOnly' => (bool) request()->attributes->get('admin_read_only'),
        ]);
    }

    public function update(Request $request, int $serverId, DonationGameExchange $exchange,
        ModuleAdminAuthorizer $access, AdminPathSettings $paths): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.exchange.update', 'PUT', $admin);
        $values = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'item_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'items_per_coin' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
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
}
