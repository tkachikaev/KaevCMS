@extends('admin.layouts.panel')
@section('title', __('module-voting::messages.title'))
@section('description', __('module-voting::messages.description'))
@section('docs', 'modules')
@section('content')
    @php($adminPath = request()->route('adminPath'))
    @include('module-voting::admin.navigation')
    <div class="admin-card-list content-list">
        @foreach($providers as $provider)
            <x-admin.card>
                <x-admin.card-heading :title="$provider->name()" :description="__('module-voting::messages.provider_description')">
                    <x-slot:actions>
                        <span @class(['status-badge', 'status-badge-success' => $settings['enabled']])>{{ __('module-voting::messages.'.($settings['enabled'] ? 'enabled' : 'disabled')) }}</span>
                    </x-slot:actions>
                </x-admin.card-heading>
                <a class="button button-secondary" wire:navigate href="{{ route('admin.module-pages.voting.providers.ktreon.show', ['adminPath' => $adminPath]) }}">{{ __('module-voting::messages.configure') }}</a>
            </x-admin.card>
        @endforeach
    </div>
@endsection
