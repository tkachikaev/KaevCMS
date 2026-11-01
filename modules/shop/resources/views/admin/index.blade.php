@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.admin_title'))
@section('description', __('module-shop::messages.admin_description'))
@section('content')
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
        <strong>{{ __('module-shop::messages.not_ready') }}</strong>
    </div>
</div>
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
                <article class="content-row admin-card-row">
                    <div>
                        <strong class="content-row-title">{{ $server->nameFor() }}</strong>
                        <p>{{ __('module-shop::messages.tab_count', ['count' => format_number($tabCounts[$server->id] ?? 0)]) }}</p>
                        <span class="status-badge">{{ __('module-shop::messages.sales_disabled') }}</span>
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
    <x-admin.card-heading :title="__('module-shop::messages.unavailable_title')" :description="__('module-shop::messages.unavailable_description')" />
</x-admin.card>
@endsection
