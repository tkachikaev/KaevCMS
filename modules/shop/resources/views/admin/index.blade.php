@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.admin_title'))
@section('description', __('module-shop::messages.admin_description'))
@section('content')
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
        <strong>{{ $global->sales_enabled ? __('module-shop::messages.not_ready') : __('module-shop::messages.disabled') }}</strong>
    </div>
</div>
<x-admin.card>
    <x-admin.card-heading :title="__('module-shop::messages.setup_title')" :description="__('module-shop::messages.setup_description')" />
    <div class="admin-empty-state empty-state">
        <h3>{{ __('module-shop::messages.unavailable_title') }}</h3>
        <p>{{ __('module-shop::messages.unavailable_description') }}</p>
    </div>
</x-admin.card>
@endsection
