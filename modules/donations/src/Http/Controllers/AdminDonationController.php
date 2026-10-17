<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Auth\AdminPermission;
use App\Models\Admin;
use App\Support\Modules\ModuleAdminAuthorizer;
use App\Services\Admin\AdminPathSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use KaevCMS\Modules\Donations\Services\DonationPaymentReviews;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use KaevCMS\Modules\Donations\Http\Requests\SaveDonationCurrencyRateRequest;
use KaevCMS\Modules\Donations\Http\Requests\SaveDonationWorkspaceRequest;
use KaevCMS\Modules\Donations\Http\Requests\UpdateDonationLimitsRequest;
use KaevCMS\Modules\Donations\Http\Requests\UpdateDonationSettingsRequest;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationCurrencyRegistry;
use KaevCMS\Modules\Donations\Services\DonationMoney;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\DonationWorkspace;

final class AdminDonationController extends Controller
{
    public function index(Request $request, DonationWorkspace $workspace, AdminPathSettings $paths, DonationCurrencyRegistry $registry, DonationMoney $money): View
    {
        /** @var view-string $view */
        $view = 'module-donations::admin.index';

        $state = $workspace->read();
        $limits = ['min_coins' => $state['min_coins'], 'max_coins' => $state['max_coins']];
        $rawCount = $request->query('preview_coins', '100');
        $count = is_string($rawCount) && preg_match('/\A[1-9][0-9]{0,6}\z/D', $rawCount)
            ? (int) $rawCount : 100;
        $count = min($limits['max_coins'], max($limits['min_coins'], $count));

        return view($view, ['previewCoins' => $count, 'limits' => $limits,
            'showInAccount' => $state['show_in_account'],
            'snapshot' => $state['snapshot'],
            'adminPath' => $paths->path(),
            'rates' => $state['rates'],
            'currencies' => $registry->all(app()->getLocale()),
            'money' => $money,
            'registry' => $registry,
        ]);
    }

    public function saveWorkspace(SaveDonationWorkspaceRequest $request, DonationWorkspace $workspace, ModuleAdminAuthorizer $access, AdminPathSettings $paths): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.workspace.update', 'PUT', $admin);
        $workspace->save($request->validated(), $admin);

        return redirect()->route('admin.module-pages.donations.index', ['adminPath' => $paths->path()])
            ->with('status', __('module-donations::messages.workspace_saved'));
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
            'status' => ['nullable', Rule::in(['draft', 'cancelled', 'expired', 'credited'])],
            'currency' => ['nullable', 'string', 'regex:/\A[A-Z]{3}\z/D'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'reported' => ['nullable', Rule::in(['1'])],
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
        if (($filters['reported'] ?? '') === '1') {
            $query->whereIn('id', DB::table('module_donation_payment_review_events')->select('order_id'));
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
        $receipts = DB::table('module_donation_payment_receipts')->where('order_id', $order->id)
            ->orderByDesc('id')->limit(30)->get();
        $candidateIds = DB::table('module_donation_payment_preparations')->where('order_id', $order->id)->pluck('id');
        $recovery = DB::table('module_donation_recovery_candidates')->whereIn('preparation_id', $candidateIds)
            ->orderByDesc('id')->limit(30)->get();
        $reviews = DB::table('module_donation_payment_review_events as r')
            ->join('admins as a', 'a.id', '=', 'r.admin_id')
            ->where('r.order_id', $order->id)
            ->select(['r.id', 'r.receipt_id', 'r.kind', 'r.source', 'r.reason', 'r.created_at', 'a.name as admin_name'])
            ->orderByDesc('r.id')->limit(100)->get();
        $admin = auth('admin')->user();
        $events = DB::table('module_donation_order_events')
            ->where('order_id', $order->id)->orderByDesc('id')->paginate(30);

        /** @var view-string $view */
        $view = 'module-donations::admin.order';

        return view($view, [
            'order' => $order, 'events' => $events, 'receipts' => $receipts, 'recovery' => $recovery, 'reviews' => $reviews,
            'canReport' => $admin instanceof Admin && $admin->hasPermission(AdminPermission::ModulesManage),
            'reportRequestUuid' => (string) Str::uuid(), 'money' => $money, 'adminPath' => $paths->path(),
        ]);
    }

    public function reportReview(string $orderUuid, Request $request, AdminPathSettings $paths,
        ModuleAdminAuthorizer $access, DonationPaymentReviews $reviews): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.orders.reviews.store', 'POST', $admin);
        $data = $request->validate([
            'receipt_id' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(DonationPaymentReviews::KINDS)],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'request_uuid' => ['required', 'uuid'],
        ]);
        try {
            $reviews->record($admin, $orderUuid, (int) $data['receipt_id'],
                (string) $data['kind'], (string) $data['reason'], (string) $data['request_uuid']);
        } catch (InvalidArgumentException|LogicException) {
            return back()->withErrors(['review' => __('module-donations::messages.review_invalid')]);
        }

        return redirect()->route('admin.module-pages.donations.orders.show', [
            'adminPath' => $paths->path(), 'orderUuid' => $orderUuid,
        ])->with('status', __('module-donations::messages.review_saved'));
    }

}
