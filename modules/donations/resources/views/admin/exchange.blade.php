@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.exchange_title'))
@section('description', __('module-donations::messages.exchange_admin_help'))
@section('content')
@include('module-donations::admin.navigation')
<x-admin.card>
    <x-admin.card-heading :title="__('module-donations::messages.exchange_title')" :description="__('module-donations::messages.exchange_admin_help')" />
    @error('exchange')<p class="alert alert-danger" role="alert">{{ $message }}</p>@enderror
    @if($servers === [])
        <p>{{ __('module-donations::messages.exchange_no_servers') }}</p>
    @endif
    <div class="settings-grid" data-testid="donations-exchange-settings">
        @foreach($servers as $server)
            <form method="POST" action="{{ route('admin.module-pages.donations.exchange.update', ['adminPath' => $adminPath, 'serverId' => $server['server_id']]) }}" class="account-form-card">
                @csrf @method('PUT')
                <h3>{{ $server['server_name'] }}</h3>
                <x-admin.field for="exchange-item-{{ $server['server_id'] }}" :label="__('module-donations::messages.exchange_item_id')">
                    <input id="exchange-item-{{ $server['server_id'] }}" name="item_id" type="number" min="1" max="2147483647" value="{{ $server['item_id'] }}" @disabled($readOnly)>
                </x-admin.field>
                <x-admin.field for="exchange-rate-{{ $server['server_id'] }}" :label="__('module-donations::messages.exchange_rate')">
                    <input id="exchange-rate-{{ $server['server_id'] }}" name="items_per_coin" type="number" min="1" max="1000000" value="{{ $server['items_per_coin'] }}" @disabled($readOnly)>
                </x-admin.field>
                <p class="account-field-help">{{ __('module-donations::messages.exchange_rate_help') }}</p>
                <x-admin.toggle id="exchange-enabled-{{ $server['server_id'] }}" name="enabled" :label="__('module-donations::messages.exchange_enabled')" :checked="$server['enabled']" :disabled="$readOnly" />
                @unless($readOnly)<x-admin.button type="submit" variant="primary">{{ __('module-donations::messages.save_settings') }}</x-admin.button>@endunless
            </form>
        @endforeach
    </div>
</x-admin.card>
@endsection
