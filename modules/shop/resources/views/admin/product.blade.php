@extends('admin.layouts.panel')
@section('title', $product ? __('module-shop::messages.edit_product') : __('module-shop::messages.create_product'))
@section('description', __('module-shop::messages.server_title', ['server' => $server->nameFor()]))
@section('content')
@php($adminPath = request()->route('adminPath'))
@php($base = ['adminPath' => $adminPath, 'server' => $server->id])
@php($productRoute = $base + ['product' => $product?->id])
<p><x-admin.button wire:navigate :href="route('admin.module-pages.shop.servers.show', $base)">{{ __('module-shop::messages.back') }}</x-admin.button></p>
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())
    <div class="notice notice-error" role="alert"><strong>{{ __('module-shop::messages.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="notice notice-info" role="note">{{ __('module-shop::messages.sales_disabled_notice') }}</div>
<x-admin.card>
    <x-admin.card-heading :title="$product?->nameFor() ?? __('module-shop::messages.create_product')" :description="$type === 'item' ? __('module-shop::messages.item_type') : __('module-shop::messages.bundle_type')" />
    @if($categories->isEmpty())
        <div class="admin-empty-state empty-state"><h3>{{ __('module-shop::messages.no_categories_title') }}</h3><p>{{ __('module-shop::messages.no_categories_description') }}</p></div>
    @elseif($canManage)
        <form method="POST" action="{{ $product ? route('admin.module-pages.shop.products.update', $productRoute) : route('admin.module-pages.shop.products.store', $base) }}">
            @csrf
            @if($product) @method('PUT') @else <input type="hidden" name="type" value="{{ $type }}"> @endif
            @include('module-shop::admin.product-fields', ['prefix' => 'shop-product', 'disabled' => false])
            <x-admin.button type="submit" variant="primary">{{ __('module-shop::messages.save') }}</x-admin.button>
        </form>
    @else
        <p>{{ __('module-shop::messages.read_only') }}</p>
        @include('module-shop::admin.product-fields', ['prefix' => 'shop-product-view', 'disabled' => true])
    @endif
</x-admin.card>
@if($product && $type === 'bundle')
    <x-admin.card>
        <x-admin.card-heading :title="__('module-shop::messages.bundle_items_title')" :description="__('module-shop::messages.bundle_items_description')" />
        @if($product->bundleItems->isEmpty())
            <div class="admin-empty-state empty-state"><h3>{{ __('module-shop::messages.no_bundle_items') }}</h3><p>{{ __('module-shop::messages.bundle_components_hint') }}</p></div>
        @else
            <div class="admin-card-list content-list">
                @foreach($product->bundleItems as $item)
                    <article class="content-row admin-card-row">
                        <div><strong class="content-row-title">{{ __('module-shop::messages.item_label', ['id' => format_number($item->item_id)]) }}</strong><p>{{ __('module-shop::messages.quantity_value', ['count' => format_number($item->quantity)]) }}</p></div>
                        @if($canManage)
                            <div class="content-row-actions admin-row-actions">
                                <form method="POST" action="{{ route('admin.module-pages.shop.bundle-items.destroy', $productRoute + ['bundleItem' => $item->id]) }}">
                                    @csrf @method('DELETE')
                                    <label><input type="checkbox" name="confirm" value="1" required> {{ __('module-shop::messages.delete_confirm') }}</label>
                                    <x-admin.button type="submit" variant="secondary">{{ __('module-shop::messages.delete') }}</x-admin.button>
                                </form>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </x-admin.card>
    @if($canManage)
        <x-admin.card>
            <x-admin.card-heading :title="__('module-shop::messages.add_bundle_item')" />
            <form method="POST" action="{{ route('admin.module-pages.shop.bundle-items.store', $productRoute) }}">
                @csrf
                <x-admin.field for="bundle-item-id" :label="__('module-shop::messages.item_id')">
                    <input id="bundle-item-id" name="item_id" type="number" min="1" max="2147483647" required value="{{ old('item_id') }}">
                </x-admin.field>
                <x-admin.field for="bundle-quantity" :label="__('module-shop::messages.quantity')">
                    <input id="bundle-quantity" name="quantity" type="number" min="1" max="2000000000" required value="{{ old('quantity', 1) }}">
                </x-admin.field>
                <x-admin.button type="submit" variant="primary">{{ __('module-shop::messages.add_bundle_item') }}</x-admin.button>
            </form>
        </x-admin.card>
    @endif
@endif
@if($product && $canManage)
    <x-admin.card>
        <x-admin.card-heading :title="__('module-shop::messages.delete_product_title')" :description="__('module-shop::messages.delete_product_warning')" />
        <form method="POST" action="{{ route('admin.module-pages.shop.products.destroy', $productRoute) }}">
            @csrf @method('DELETE')
            <label><input type="checkbox" name="confirm" value="1" required> {{ __('module-shop::messages.delete_confirm') }}</label>
            <p><x-admin.button type="submit" variant="secondary">{{ __('module-shop::messages.delete') }}</x-admin.button></p>
        </form>
    </x-admin.card>
@endif
@endsection
