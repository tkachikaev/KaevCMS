@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.server_title', ['server' => $server->nameFor()]))
@section('description', __('module-shop::messages.server_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<p><x-admin.button wire:navigate :href="route('admin.module-pages.shop.index', ['adminPath' => $adminPath])">{{ __('module-shop::messages.back') }}</x-admin.button></p>
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())
    <div class="notice notice-error" role="alert">
        <strong>{{ __('module-shop::messages.validation_error') }}</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
<div class="notice notice-info" role="note">{{ __('module-shop::messages.sales_disabled_notice') }}</div>
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
                <article class="content-row admin-card-row">
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
                    <article class="content-row admin-card-row">
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
@endsection
