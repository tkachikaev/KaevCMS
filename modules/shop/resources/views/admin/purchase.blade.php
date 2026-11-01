@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.purchase_receipt', ['id' => format_number($purchase->id)]))
@section('description', __('module-shop::messages.purchase_admin_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<p><x-admin.button wire:navigate :href="route('admin.module-pages.shop.purchases.index', ['adminPath' => $adminPath])">{{ __('module-shop::messages.purchase_admin_back') }}</x-admin.button></p>
<x-admin.card>
    <x-admin.card-heading :title="$purchase->nameFor()" :description="__('module-shop::messages.purchase_snapshot_hint')" />
    <div class="table-responsive">
        <table class="admin-table"><tbody>
            <tr><th>{{ __('module-shop::messages.purchase_reference') }}</th><td>{{ $purchase->request_uuid }}</td></tr>
            <tr><th>{{ __('module-shop::messages.purchase_date') }}</th><td><time datetime="{{ $purchase->created_at->toIso8601String() }}">{{ $purchase->created_at->format('d.m.Y H:i') }}</time></td></tr>
            <tr><th>{{ __('module-shop::messages.purchase_user') }}</th><td>{{ $purchase->user?->name ?? ('#'.$purchase->user_id) }} (#{{ format_number($purchase->user_id) }})</td></tr>
            <tr><th>{{ __('module-shop::messages.purchase_server') }}</th><td>{{ $purchase->gameServer?->nameFor() }}</td></tr>
            <tr><th>{{ __('module-shop::messages.purchase_kind') }}</th><td>{{ __('module-shop::messages.account_'.$purchase->product_type) }}</td></tr>
            <tr><th>{{ __('module-shop::messages.purchase_price') }}</th><td class="admin-num">{{ format_number($purchase->price_coins) }}</td></tr>
            <tr><th>{{ __('module-shop::messages.purchase_wallet_entry') }}</th><td>#{{ format_number($purchase->wallet_entry_id) }}</td></tr>
            <tr><th>{{ __('module-shop::messages.purchase_inventory_grant') }}</th><td>#{{ format_number($purchase->reward_inventory_grant_id) }}</td></tr>
        </tbody></table>
    </div>
</x-admin.card>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.purchase_contents')" />
    <div class="table-responsive"><table class="admin-table"><thead><tr>
        <th>{{ __('module-shop::messages.item_id') }}</th><th>{{ __('module-shop::messages.quantity') }}</th>
    </tr></thead><tbody>
        @forelse($purchase->items_snapshot ?? [] as $item)
            <tr><td>{{ format_number((int) ($item['item_id'] ?? 0)) }}</td><td class="admin-num">{{ format_number((int) ($item['quantity'] ?? 0)) }}</td></tr>
        @empty
            <tr><td colspan="2">{{ __('module-shop::messages.purchase_no_contents') }}</td></tr>
        @endforelse
    </tbody></table></div>
    <p>{{ __('module-shop::messages.purchase_read_only') }}</p>
</x-admin.card>
@endsection
