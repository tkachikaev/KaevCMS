@extends('admin.layouts.panel')
@section('title', __('module-voting::messages.title'))
@section('description', __('module-voting::messages.description'))
@section('docs', 'modules')
@section('content')
    @php($adminPath = request()->route('adminPath'))
    @include('module-voting::admin.navigation')
    <div class="donations-provider-grid" data-testid="voting-providers">
        @foreach($providers as $provider)
            <x-admin.card class="donations-provider-card" data-provider="{{ $provider->code() }}">
                <div class="donations-provider-heading">
                    <span class="donations-provider-logo" aria-hidden="true">
                        <img src="{{ asset('assets/modules/ktreon.svg') }}" width="40" height="40" alt="">
                    </span>
                    <div><h2>{{ $provider->name() }}</h2></div>
                </div>
                <p class="donations-provider-description">{{ __('module-voting::messages.provider_description') }}</p>
                <div class="donations-provider-badges">
                    <span @class(['status-badge', 'status-badge-success' => $settings['enabled']])>{{ __('module-voting::messages.'.($settings['enabled'] ? 'enabled' : 'disabled')) }}</span>
                </div>
                <a class="button button-secondary" wire:navigate href="{{ route('admin.module-pages.voting.providers.ktreon.show', ['adminPath' => $adminPath]) }}">{{ __('module-voting::messages.configure') }}</a>
            </x-admin.card>
        @endforeach
    </div>
@endsection
