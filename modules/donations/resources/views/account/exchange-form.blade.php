<div data-exchange-content>
    <div class="donations-exchange-success" data-exchange-success @if(! $successInventoryUrl) hidden @endif role="status">
        <span class="donations-exchange-check" aria-hidden="true">✓</span>
        <h3 tabindex="-1" data-exchange-success-title>{{ __('module-donations::messages.exchange_success_title') }}</h3>
        <strong data-exchange-received></strong>
        <p>{{ __('module-donations::messages.exchange_success_help') }}</p>
        <a wire:navigate class="account-button primary" href="{{ $successInventoryUrl ?: public_route('web-inventory.index') }}" data-exchange-inventory>{{ __('module-donations::messages.exchange_inventory_open') }}</a>
    </div>
    @if(! $successInventoryUrl)
        @if($servers === [])
            <p class="account-field-help">{{ __('module-donations::messages.exchange_unavailable') }}</p>
        @else
        <form method="POST" action="{{ route('modules.donations.exchange.store') }}" class="donations-exchange-form" data-exchange-form
              data-max-coins="{{ $maxCoins }}" data-max-game-items="{{ $maxGameItems }}" data-balance="{{ $walletView['balance'] }}"
              data-failed="{{ __('module-donations::messages.exchange_failed') }}" data-wallet-menu-label="{{ __('Wallet menu') }}">
            @csrf
            <input type="hidden" name="request_uuid" value="{{ $requestUuid }}">
            <div class="donations-exchange-balance"><x-account-wallet-icon :url="$walletView['icon_url']" /><span>{{ __('Available balance') }}<strong data-wallet-balance-label>{{ $walletView['label'] }}</strong></span></div>
            @if($walletView['blocked'])<p class="account-field-error">{{ __('module-donations::messages.exchange_blocked') }}</p>@endif
            <label for="exchange-server">{{ __('module-donations::messages.exchange_server') }}
                <div class="account-field-control"><select name="server_id" id="exchange-server" required data-exchange-server>
                    @foreach($servers as $server)
                        <option value="{{ $server['server_id'] }}" data-factor="{{ $server['items_per_coin'] }}" data-name="{{ $server['item']['name'] }}" data-icon="{{ $server['item']['icon'] }}" @selected((string) old('server_id') === (string) $server['server_id'])>{{ $server['server_name'] }}</option>
                    @endforeach
                </select></div>
            </label>
            <label for="exchange-coins">{{ __('module-donations::messages.exchange_coins') }}
                <div class="account-field-control"><input id="exchange-coins" name="coins" type="number" min="1" max="{{ $maxCoins }}" step="1" inputmode="numeric" value="{{ old('coins', 1) }}" required data-exchange-coins></div>
            </label>
            <div class="donations-exchange-preview" data-exchange-preview hidden>
                <img src="{{ $walletView['icon_url'] }}" alt="" width="28" height="28" data-exchange-item-icon>
                <div><small>{{ __('module-donations::messages.exchange_receive') }}</small><strong aria-live="polite" data-exchange-receive></strong></div>
            </div>
            <p class="account-field-help donations-exchange-note">{{ __('module-donations::messages.exchange_delivery_note') }}</p>
            <p class="account-field-error" role="alert" data-exchange-error @if(! $errors->has('exchange')) hidden @endif>{{ $errors->first('exchange') }}</p>
            <button class="account-button primary" type="submit" @disabled($walletView['blocked'])>{{ __('module-donations::messages.exchange_wallet_action') }}</button>
        </form>
        @endif
    @endif
</div>
