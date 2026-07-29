@extends('admin.layouts.panel')
@section('title', __('module-support-tickets::messages.settings_title'))
@section('description', __('module-support-tickets::messages.settings_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<div class="content-toolbar"><a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.support-tickets.index', ['adminPath' => $adminPath]) }}">← {{ __('module-support-tickets::messages.back_to_tickets') }}</a></div>
<form class="settings-card" method="POST" action="{{ route('admin.module-pages.support-tickets.settings.update', ['adminPath' => $adminPath]) }}">
    @csrf
    @method('PUT')
    <div class="settings-card-heading"><div><h2>{{ __('module-support-tickets::messages.allow_editor_management') }}</h2><p>{{ __('module-support-tickets::messages.allow_editor_management_help') }}</p></div></div>
    <input type="hidden" name="allow_editor_management" value="0">
    <label class="toggle-row"><span><strong>{{ __('module-support-tickets::messages.allow_editor_management') }}</strong><small>{{ __('module-support-tickets::messages.allow_editor_management_help') }}</small></span><input name="allow_editor_management" type="checkbox" value="1" @checked(old('allow_editor_management', $allowEditorManagement))></label>
    <div class="settings-actions"><button class="button button-primary" type="submit">{{ __('module-support-tickets::messages.save_settings') }}</button></div>
</form>
@endsection
@push('head')<link rel="stylesheet" href="{{ asset('assets/modules/support-tickets.css') }}?v={{ cms_version() }}" data-navigate-track>@endpush
