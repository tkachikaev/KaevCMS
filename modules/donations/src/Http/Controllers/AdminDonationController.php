<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminPathSettings;
use App\Support\Modules\ModuleAdminAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use KaevCMS\Modules\Donations\Http\Requests\SaveDonationCurrencyRateRequest;
use KaevCMS\Modules\Donations\Http\Requests\UpdateDonationLimitsRequest;
use KaevCMS\Modules\Donations\Http\Requests\UpdateDonationSettingsRequest;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationCurrencyRegistry;
use KaevCMS\Modules\Donations\Services\DonationMoney;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;

final class AdminDonationController extends Controller
{
    public function index(Request $request, DonationSettings $settings, AdminPathSettings $paths, DonationRates $rates, DonationCurrencyRegistry $registry, DonationMoney $money): View
    {
        /** @var view-string $view */
        $view = 'module-donations::admin.index';

        $limits = $rates->limits();
        $rawCount = $request->query('preview_coins', '100');
        $count = is_string($rawCount) && preg_match('/\A[1-9][0-9]{0,6}\z/D', $rawCount)
            ? (int) $rawCount : 100;
        $count = min($limits['max_coins'], max($limits['min_coins'], $count));

        return view($view, ['previewCoins' => $count, 'limits' => $limits,
            'showInAccount' => $settings->isVisibleToPlayers(),
            'adminPath' => $paths->path(),
            'rates' => $rates->configured(),
            'currencies' => $registry->all(app()->getLocale()),
            'money' => $money,
            'registry' => $registry,
        ]);
    }

    public function limits(
        UpdateDonationLimitsRequest $request,
        DonationRates $rates,
        ModuleAdminAuthorizer $access,
        AdminPathSettings $paths,
    ): RedirectResponse {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.limits.update', 'PUT', $admin);
        $validated = $request->validated();
        $rates->saveLimits((int) $validated['min_coins'], (int) $validated['max_coins'], $admin);

        return redirect()->route('admin.module-pages.donations.index', ['adminPath' => $paths->path()])
            ->with('status', __('module-donations::messages.limits_saved'));
    }

    public function rate(
        SaveDonationCurrencyRateRequest $request,
        DonationRates $rates,
        ModuleAdminAuthorizer $access,
        AdminPathSettings $paths,
    ): RedirectResponse {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.rates.store', 'POST', $admin);
        $validated = $request->validated();
        $rates->saveRate((string) $validated['currency_code'], (string) $validated['unit_price'],
            (bool) $validated['enabled'], $admin);

        return redirect()->route('admin.module-pages.donations.index', ['adminPath' => $paths->path()])
            ->with('status', __('module-donations::messages.rate_saved'));
    }

    public function update(
        UpdateDonationSettingsRequest $request,
        DonationSettings $settings,
        ModuleAdminAuthorizer $access,
        AdminPathSettings $paths,
    ): RedirectResponse {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.settings.update', 'PUT', $admin);

        $settings->updateVisibility((bool) $request->validated('show_in_account'), $admin);

        return redirect()->route('admin.module-pages.donations.index', ['adminPath' => $paths->path()])
            ->with('status', __('module-donations::messages.settings_saved'));
    }

    public function orders(Request $request, AdminPathSettings $paths, DonationMoney $money): View
    {
        $filters = validator($request->query(), [
            'status' => ['nullable', Rule::in(['draft', 'cancelled', 'expired'])],
            'currency' => ['nullable', 'string', 'regex:/\A[A-Z]{3}\z/D'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $query = DonationOrder::query()->with('user:id,name,email');
        if (($filters['status'] ?? '') === 'expired') {
            $query->where(function ($query): void {
                $query->where('status', 'expired')
                    ->orWhere(function ($query): void {
                        $query->where('status', 'draft')->where('expires_at', '<=', now());
                    });
            });
        } elseif (($filters['status'] ?? '') === 'draft') {
            $query->where('status', 'draft')->where('expires_at', '>', now());
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['currency'])) {
            $query->where('currency_code', $filters['currency']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        /** @var view-string $view */
        $view = 'module-donations::admin.orders';

        return view($view, [
            'orders' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => $filters, 'money' => $money, 'adminPath' => $paths->path(),
        ]);
    }

    public function showOrder(string $orderUuid, AdminPathSettings $paths, DonationMoney $money): View
    {
        $order = DonationOrder::query()->with('user:id,name,email')->where('order_uuid', $orderUuid)->firstOrFail();
        $events = DB::table('module_donation_order_events')
            ->where('order_id', $order->id)->orderByDesc('id')->paginate(30);

        /** @var view-string $view */
        $view = 'module-donations::admin.order';

        return view($view, [
            'order' => $order, 'events' => $events, 'money' => $money, 'adminPath' => $paths->path(),
        ]);
    }
}
