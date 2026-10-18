@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.orders_admin_title'))
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="donations-admin-orders">
    <h2>{{ __('module-donations::messages.orders_admin_title') }}</h2>
    <form method="GET" action="{{ route('admin.module-pages.donations.orders.index', ['adminPath' => $adminPath]) }}" class="settings-form">
        <div class="settings-grid">
            <div class="form-group"><label for="orders-status">{{ __('module-donations::messages.order_status') }}</label><select id="orders-status" name="status"><option value="">{{ __('module-donations::messages.all_statuses') }}</option>@foreach(['draft','cancelled','expired','credited'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('module-donations::messages.status_'.$status) }}</option>@endforeach</select></div>
            <div class="form-group"><label for="orders-currency">{{ __('module-donations::messages.currency') }}</label><input id="orders-currency" name="currency" maxlength="3" value="{{ $filters['currency'] ?? '' }}" placeholder="RUB"></div>
            <div class="form-group"><label for="orders-user">{{ __('module-donations::messages.user_id') }}</label><input id="orders-user" name="user_id" type="number" min="1" value="{{ $filters['user_id'] ?? '' }}"></div>
            <div class="form-group"><label for="orders-reported">{{ __('module-donations::messages.review_filter') }}</label><select id="orders-reported" name="reported"><option value="">{{ __('module-donations::messages.review_filter_all') }}</option><option value="1" @selected(($filters['reported'] ?? '') === '1')>{{ __('module-donations::messages.review_filter_reports') }}</option></select></div>
        </div><button type="submit" class="button button-secondary">{{ __('module-donations::messages.filter') }}</button>
    </form>
    <div class="table-responsive"><table class="admin-table"><thead><tr><th>ID</th><th>{{ __('module-donations::messages.user_id') }}</th><th>{{ __('module-donations::messages.order_status') }}</th><th>{{ __('module-donations::messages.currency') }}</th><th>{{ __('module-donations::messages.player_estimated_total') }}</th></tr></thead><tbody>
    @forelse($orders as $order)
      <tr><td><a wire:navigate href="{{ route('admin.module-pages.donations.orders.show', ['adminPath' => $adminPath, 'orderUuid' => $order->order_uuid]) }}">#{{ $order->id }}</a></td><td>{{ $order->user?->name ?? ('#'.$order->user_id) }} (#{{ $order->user_id }})</td><td>{{ __('module-donations::messages.status_'.$order->displayStatus()) }}</td><td>{{ $order->currency_code }}</td><td>{{ $money->format((int) $order->total_minor, (int) $order->exponent) }}</td></tr>
    @empty<tr><td colspan="5">{{ __('module-donations::messages.no_orders') }}</td></tr>@endforelse
    </tbody></table></div>{{ $orders->links() }}
</section>
@endsection
