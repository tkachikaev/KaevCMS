@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.edit_title'))
@section('description', __('module-shop::messages.server_title', ['server' => $server->nameFor()]))
@section('content')
<div class="admin-section-stack">
@php($adminPath = request()->route('adminPath'))
@php($isTab = $kind === 'tabs')
@php($routeIds = ['adminPath' => $adminPath, 'server' => $server->id, $isTab ? 'tab' : 'category' => $record->id])
<p><x-admin.button wire:navigate :href="route('admin.module-pages.shop.servers.show', ['adminPath' => $adminPath, 'server' => $server->id])">{{ __('module-shop::messages.back') }}</x-admin.button></p>
@if($errors->any())<div class="notice notice-error" role="alert"><strong>{{ __('module-shop::messages.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<x-admin.card>
    <x-admin.card-heading :title="$record->nameFor()" :description="__('module-shop::messages.edit_description')" />
    @if($canManage)
        <form method="POST" action="{{ route('admin.module-pages.shop.'.$kind.'.update', $routeIds) }}">
            @csrf @method('PUT')
            @include('module-shop::admin.fields', ['prefix' => 'edit-section', 'disabled' => false])
            <x-admin.button type="submit" variant="primary">{{ __('module-shop::messages.save') }}</x-admin.button>
        </form>
    @else
        <p>{{ __('module-shop::messages.read_only') }}</p>
        @include('module-shop::admin.fields', ['prefix' => 'view-section', 'disabled' => true])
    @endif
</x-admin.card>
@if($canManage)
    <x-admin.card>
        <x-admin.card-heading :title="__('module-shop::messages.delete_title')" :description="__('module-shop::messages.delete_warning')" />
        <form method="POST" action="{{ route('admin.module-pages.shop.'.$kind.'.destroy', $routeIds) }}">
            @csrf @method('DELETE')
            <label><input type="checkbox" name="confirm" value="1" required> {{ __('module-shop::messages.delete_confirm') }}</label>
            <p><x-admin.button type="submit" variant="secondary">{{ __('module-shop::messages.delete') }}</x-admin.button></p>
        </form>
    </x-admin.card>
@endif
</div>
@endsection
