@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.admin_title'))
@section('description', __('module-shop::messages.admin_description'))
@section('content')
<div class="admin-section-stack">
@php($adminPath = request()->route('adminPath'))
<div class="admin-overview">
    <div class="admin-overview-stat content-stat">
        <span>{{ __('module-shop::messages.servers') }}</span>
        <strong class="admin-num">{{ format_number($serverCount) }}</strong>
    </div>
    <div class="admin-overview-stat content-stat">
        <span>{{ __('module-shop::messages.configured_servers') }}</span>
        <strong class="admin-num">{{ format_number($configuredCount) }}</strong>
    </div>
    <div class="admin-overview-stat content-stat">
        <span>{{ __('module-shop::messages.sales_status') }}</span>
        <strong>{{ $global->sales_enabled && $sourceEnabled ? __('module-shop::messages.sales_enabled') : __('module-shop::messages.sales_disabled') }}</strong>
    </div>
</div>
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice notice-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.sales_settings')" />
    <form method="POST" action="{{ route('admin.module-pages.shop.settings.update', ['adminPath' => $adminPath]) }}">
        @csrf @method('PUT')
        <x-admin.toggle id="shop_sales_enabled" name="sales_enabled" :label="__('module-shop::messages.accept_purchases')" :checked="(bool) old('sales_enabled', $global->sales_enabled)" :disabled="! $canManage" />
        @if($canManage)<div class="admin-row-actions"><x-admin.button type="submit" variant="primary">{{ __('module-shop::messages.save') }}</x-admin.button></div>@endif
    </form>
</x-admin.card>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.servers_title')" :description="__('module-shop::messages.servers_description')" />
    @if($servers->isEmpty())
        <div class="admin-empty-state empty-state">
            <h3>{{ __('module-shop::messages.no_servers_title') }}</h3>
            <p>{{ __('module-shop::messages.no_servers_description') }}</p>
        </div>
    @else
        <div class="admin-card-list content-list">
            @foreach($servers as $server)
                <article class="admin-card-row admin-card-row-simple">
                    <div>
                        <strong class="content-row-title">{{ $server->nameFor() }}</strong>
                        <p>{{ __('module-shop::messages.tab_count', ['count' => format_number($tabCounts[$server->id] ?? 0)]) }}</p>
                        <span @class(['status-badge', 'status-badge-success' => $global->sales_enabled && $sourceEnabled && $serverSettings->get($server->id)?->enabled])>{{ $global->sales_enabled && $sourceEnabled && $serverSettings->get($server->id)?->enabled ? __('module-shop::messages.sales_enabled') : __('module-shop::messages.sales_disabled') }}</span>
                    </div>
                    <div class="content-row-actions admin-row-actions">
                        <x-admin.button wire:navigate :href="route('admin.module-pages.shop.servers.show', ['adminPath' => $adminPath, 'server' => $server->id])">{{ __('module-shop::messages.configure') }}</x-admin.button>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</x-admin.card>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.purchase_admin_title')" :description="__('module-shop::messages.purchase_admin_description')" />
    <x-admin.button wire:navigate :href="route('admin.module-pages.shop.purchases.index', ['adminPath' => $adminPath])">{{ __('module-shop::messages.purchase_open_history') }}</x-admin.button>
</x-admin.card>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.unavailable_title')" :description="__('module-shop::messages.unavailable_description')" />
</x-admin.card>
</div>
@endsection
