@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.purchase_admin_title'))
@section('description', __('module-shop::messages.purchase_admin_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<p><x-admin.button wire:navigate :href="route('admin.module-pages.shop.index', ['adminPath' => $adminPath])">{{ __('module-shop::messages.purchase_shop_back') }}</x-admin.button></p>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.purchase_filters')" :description="__('module-shop::messages.purchase_read_only')" />
    <form class="settings-form" method="GET" action="{{ route('admin.module-pages.shop.purchases.index', ['adminPath' => $adminPath]) }}">
        <div class="settings-grid">
            <div class="form-group">
                <label for="shop-server">{{ __('module-shop::messages.purchase_filter_server') }}</label>
                <select id="shop-server" name="server_id">
                    <option value="">{{ __('module-shop::messages.purchase_filter_all_servers') }}</option>
                    @foreach($servers as $server)
                        <option value="{{ $server->id }}" @selected((string) ($filters['server_id'] ?? '') === (string) $server->id)>{{ $server->nameFor() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="shop-user">{{ __('module-shop::messages.purchase_filter_user') }}</label>
                <input id="shop-user" type="number" min="1" name="user_id" value="{{ $filters['user_id'] ?? '' }}">
            </div>
            <div class="form-group">
                <label for="shop-from">{{ __('module-shop::messages.purchase_filter_from') }}</label>
                <input id="shop-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="form-group">
                <label for="shop-to">{{ __('module-shop::messages.purchase_filter_to') }}</label>
                <input id="shop-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
        </div>
        <x-admin.button type="submit">{{ __('module-shop::messages.purchase_filter_apply') }}</x-admin.button>
        <x-admin.button wire:navigate :href="route('admin.module-pages.shop.purchases.index', ['adminPath' => $adminPath])">{{ __('module-shop::messages.purchase_filter_reset') }}</x-admin.button>
    </form>
</x-admin.card>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.purchase_admin_title')" :description="__('module-shop::messages.purchase_snapshot_hint')" />
    <div class="table-responsive">
        <table class="admin-table">
            <thead><tr>
                <th>{{ __('module-shop::messages.purchase_reference') }}</th>
                <th>{{ __('module-shop::messages.purchase_date') }}</th>
                <th>{{ __('module-shop::messages.purchase_user') }}</th>
                <th>{{ __('module-shop::messages.purchase_server') }}</th>
                <th>{{ __('module-shop::messages.purchase_item') }}</th>
                <th>{{ __('module-shop::messages.purchase_price') }}</th>
            </tr></thead>
            <tbody>
                @forelse($purchases as $purchase)
                    <tr>
                        <td><a wire:navigate href="{{ route('admin.module-pages.shop.purchases.show', ['adminPath' => $adminPath, 'purchase' => $purchase->id]) }}">#{{ format_number($purchase->id) }}</a></td>
                        <td><time datetime="{{ $purchase->created_at->toIso8601String() }}">{{ $purchase->created_at->format('d.m.Y H:i') }}</time></td>
                        <td>{{ $purchase->user?->name ?? ('#'.$purchase->user_id) }} (#{{ format_number($purchase->user_id) }})</td>
                        <td>{{ $purchase->gameServer?->nameFor() }}</td>
                        <td>{{ $purchase->nameFor() }}</td>
                        <td class="admin-num">{{ format_number($purchase->price_coins) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">{{ __('module-shop::messages.purchase_empty_admin') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $purchases->links() }}
</x-admin.card>
@endsection
