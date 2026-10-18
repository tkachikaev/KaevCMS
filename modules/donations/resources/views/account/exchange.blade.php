@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.exchange_title'))
@section('description', __('module-donations::messages.exchange_account_help'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section" data-testid="donations-game-exchange">
        <x-account-panel-header :title="__('module-donations::messages.exchange_title')" />
        <p>{{ __('module-donations::messages.exchange_account_help') }}</p>
        <p class="account-field-help">{{ __('module-donations::messages.current_balance_label') }}: <strong>{{ $walletView['label'] }}</strong></p>
        @if($walletView['blocked'])<p class="account-field-error">{{ __('module-donations::messages.exchange_blocked') }}</p>@endif
        @error('exchange')<p class="account-field-error" role="alert">{{ $message }}</p>@enderror
        @if($servers === [])
            <p class="account-field-help">{{ __('module-donations::messages.exchange_unavailable') }}</p>
        @else
        <form method="POST" action="{{ route('modules.donations.exchange.store') }}" class="account-form-card" data-exchange-form
              data-max-coins="{{ $maxCoins }}" data-max-game-items="{{ $maxGameItems }}">
            @csrf
            <input type="hidden" name="request_uuid" value="{{ $requestUuid }}">
            <label for="exchange-server">{{ __('module-donations::messages.exchange_server') }}
                <div class="account-field-control"><select name="server_id" id="exchange-server" required data-exchange-server>
                    @foreach($servers as $server)
                        <option value="{{ $server['server_id'] }}" data-factor="{{ $server['items_per_coin'] }}" data-item="{{ $server['item_id'] }}" @selected((string) old('server_id') === (string) $server['server_id'])>{{ $server['server_name'] }}</option>
                    @endforeach
                </select></div>
            </label>
            <label for="exchange-coins">{{ __('module-donations::messages.exchange_coins') }}
                <div class="account-field-control"><input id="exchange-coins" name="coins" type="number" min="1" max="{{ $maxCoins }}" step="1" inputmode="numeric" value="{{ old('coins', 1) }}" required data-exchange-coins></div>
            </label>
            <p aria-live="polite" data-exchange-summary>{{ __('module-donations::messages.exchange_preview_loading') }}</p>
            <p class="account-field-help">{{ __('module-donations::messages.exchange_delivery_note') }}</p>
            <button class="account-button primary" type="submit" @disabled($walletView['blocked'])>{{ __('module-donations::messages.exchange_submit') }}</button>
        </form>
        @endif
    </x-account-panel>
    <x-account-panel class="account-section">
        <x-account-panel-header :title="__('module-donations::messages.exchange_history')" />
        @forelse($history as $item)
            <p>{{ $item->server_name }} · {{ $item->coins }} → {{ $item->game_amount }} (ID {{ $item->item_id }}) · {{ $item->created_at }}</p>
        @empty
            <p>{{ __('module-donations::messages.exchange_history_empty') }}</p>
        @endforelse
    </x-account-panel>
</x-account-page>
@endsection
@push('scripts')
<script src="{{ asset('assets/modules/donations-exchange.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
