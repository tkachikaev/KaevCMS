@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.server_title', ['server' => $server->nameFor()]))
@section('description', __('module-shop::messages.server_description'))
@section('content')
<div class="admin-section-stack">
@php($adminPath = request()->route('adminPath'))
<p><x-admin.button wire:navigate :href="route('admin.module-pages.shop.index', ['adminPath' => $adminPath])">{{ __('module-shop::messages.back') }}</x-admin.button></p>
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())
    <div class="notice notice-error" role="alert">
        <strong>{{ __('module-shop::messages.validation_error') }}</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.sales_settings')" />
    <form method="POST" action="{{ route('admin.module-pages.shop.servers.settings.update', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
        @csrf @method('PUT')
        <x-admin.toggle id="shop_server_enabled" name="enabled" :label="__('module-shop::messages.server_sales_enabled')" :checked="(bool) old('enabled', $settings?->enabled ?? false)" :disabled="! $canManage" />
        @if($canManage)<div class="admin-row-actions"><x-admin.button type="submit" variant="primary">{{ __('module-shop::messages.save') }}</x-admin.button></div>@endif
    </form>
</x-admin.card>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.tabs_title')" :description="__('module-shop::messages.tabs_description')" />
    @if($tabs->isEmpty())
        <div class="admin-empty-state empty-state">
            <h3>{{ __('module-shop::messages.no_tabs_title') }}</h3>
            <p>{{ __('module-shop::messages.no_tabs_description') }}</p>
        </div>
    @else
        <div class="admin-card-list content-list">
            @foreach($tabs as $tab)
                <article class="admin-card-row admin-card-row-simple">
                    <div>
                        <strong class="content-row-title">{{ $tab->nameFor() }}</strong>
                        <p>{{ __('module-shop::messages.category_count', ['count' => format_number($tab->categories->count())]) }} · {{ __('module-shop::messages.order_value', ['value' => format_number($tab->sort_order)]) }}</p>
                        <span @class(['status-badge', 'status-badge-success' => $tab->is_active])>{{ $tab->is_active ? __('module-shop::messages.active') : __('module-shop::messages.hidden') }}</span>
                    </div>
                    <div class="content-row-actions admin-row-actions"><x-admin.button wire:navigate :href="route('admin.module-pages.shop.tabs.edit', ['adminPath' => $adminPath, 'server' => $server->id, 'tab' => $tab->id])">{{ $canManage ? __('module-shop::messages.edit') : __('module-shop::messages.view') }}</x-admin.button></div>
                </article>
            @endforeach
        </div>
    @endif
</x-admin.card>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.categories_title')" :description="__('module-shop::messages.categories_description')" />
    @if($tabs->sum(fn ($tab) => $tab->categories->count()) === 0)
        <div class="admin-empty-state empty-state">
            <h3>{{ __('module-shop::messages.no_categories_title') }}</h3>
            <p>{{ __('module-shop::messages.no_categories_description') }}</p>
        </div>
    @else
        <div class="admin-card-list content-list">
            @foreach($tabs as $tab)
                @foreach($tab->categories as $category)
                    <article class="admin-card-row admin-card-row-simple">
                        <div>
                            <strong class="content-row-title">{{ $category->nameFor() }}</strong>
                            <p>{{ $tab->nameFor() }} · {{ __('module-shop::messages.order_value', ['value' => format_number($category->sort_order)]) }}</p>
                            <span @class(['status-badge', 'status-badge-success' => $category->is_active])>{{ $category->is_active ? __('module-shop::messages.active') : __('module-shop::messages.hidden') }}</span>
                        </div>
                        <div class="content-row-actions admin-row-actions"><x-admin.button wire:navigate :href="route('admin.module-pages.shop.categories.edit', ['adminPath' => $adminPath, 'server' => $server->id, 'category' => $category->id])">{{ $canManage ? __('module-shop::messages.edit') : __('module-shop::messages.view') }}</x-admin.button></div>
                    </article>
                @endforeach
            @endforeach
        </div>
    @endif
</x-admin.card>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.products_title')" :description="__('module-shop::messages.products_description')" />
    @if($products->isEmpty())
        <div class="admin-empty-state empty-state"><h3>{{ __('module-shop::messages.no_products_title') }}</h3><p>{{ __('module-shop::messages.no_products_description') }}</p></div>
    @else
        <div class="admin-card-list content-list">
            @foreach($products as $product)
                <article class="admin-card-row admin-card-row-simple">
                    <div>
                        <strong class="content-row-title">{{ $product->nameFor() }}</strong>
                        <p>{{ $product->category->tab->nameFor() }} / {{ $product->category->nameFor() }} · {{ $product->type === 'item' ? __('module-shop::messages.item_type') : __('module-shop::messages.bundle_type') }} · {{ __('module-shop::messages.price_value', ['count' => format_number($product->price_coins)]) }}</p>
                        <span @class(['status-badge', 'status-badge-success' => $product->is_active])>{{ $product->is_active ? __('module-shop::messages.active') : __('module-shop::messages.hidden') }}</span>
                        @if($product->type === 'bundle')
                            <p>{{ __('module-shop::messages.bundle_item_count', ['count' => format_number($product->bundleItems->count())]) }}</p>
                        @else
                            <p>{{ __('module-shop::messages.item_label', ['id' => format_number($product->item_id)]) }} · {{ __('module-shop::messages.quantity_value', ['count' => format_number($product->quantity)]) }}</p>
                        @endif
                    </div>
                    <div class="content-row-actions admin-row-actions"><x-admin.button wire:navigate :href="route('admin.module-pages.shop.products.edit', ['adminPath' => $adminPath, 'server' => $server->id, 'product' => $product->id])">{{ $canManage ? __('module-shop::messages.edit') : __('module-shop::messages.view') }}</x-admin.button></div>
                </article>
            @endforeach
        </div>
    @endif
    @if($canManage && $tabs->sum(fn ($tab) => $tab->categories->count()) > 0)
        <div class="content-row-actions admin-row-actions">
            <x-admin.button wire:navigate :href="route('admin.module-pages.shop.products.create', ['adminPath' => $adminPath, 'server' => $server->id, 'type' => 'item'])" variant="primary">{{ __('module-shop::messages.add_item_product') }}</x-admin.button>
            <x-admin.button wire:navigate :href="route('admin.module-pages.shop.products.create', ['adminPath' => $adminPath, 'server' => $server->id, 'type' => 'bundle'])">{{ __('module-shop::messages.add_bundle_product') }}</x-admin.button>
        </div>
    @endif
</x-admin.card>
@if($canManage)
    <x-admin.card>
        <x-admin.card-heading :title="__('module-shop::messages.add_tab')" />
        <form method="POST" action="{{ route('admin.module-pages.shop.tabs.store', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
            @csrf
            @include('module-shop::admin.fields', ['prefix' => 'new-tab', 'record' => null, 'kind' => 'tabs', 'tabOptions' => collect(), 'disabled' => false])
            <x-admin.button type="submit" variant="primary">{{ __('module-shop::messages.add_tab') }}</x-admin.button>
        </form>
    </x-admin.card>
    @if($tabs->isNotEmpty())
        <x-admin.card>
            <x-admin.card-heading :title="__('module-shop::messages.add_category')" />
            <form method="POST" action="{{ route('admin.module-pages.shop.categories.store', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
                @csrf
                @include('module-shop::admin.fields', ['prefix' => 'new-category', 'record' => null, 'kind' => 'categories', 'tabOptions' => $tabs, 'disabled' => false])
                <x-admin.button type="submit" variant="primary">{{ __('module-shop::messages.add_category') }}</x-admin.button>
            </form>
        </x-admin.card>
    @endif
@endif
</div>
@endsection
