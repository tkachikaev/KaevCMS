@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.providers_title'))
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="donations-provider-detail">
    <h2>{{ $driver->name() }} ({{ $driver->code() }})</h2>
    <p class="admin-field-help">{{ __('module-donations::messages.provider_activation_help') }}</p>
    @if($driver->code() === 'yoomoney')
        <p class="admin-field-help">{{ __('module-donations::messages.provider_webhook_label') }}: <code>{{ route('payments.yoomoney.notify') }}</code></p>
    @endif
    <div class="settings-form" data-testid="donations-provider-local-diagnostic">
        <strong>{{ __('module-donations::messages.provider_local_check') }}:</strong> {{ __('module-donations::messages.provider_state_'.$diagnostic['state']) }}
        @if($diagnostic['matched_currencies'] !== [])<p>{{ implode(', ', $diagnostic['matched_currencies']) }}</p>@endif
        <p class="admin-field-help">{{ __('module-donations::messages.provider_local_check_only') }}</p>
    </div>
    @if($errors->has('provider'))<p class="admin-field-error" role="alert">{{ $errors->first('provider') }}</p>@endif
    <form method="POST" action="{{ route('admin.module-pages.donations.providers.update', ['adminPath' => $adminPath, 'providerCode' => $driver->code()]) }}" class="settings-form" autocomplete="off">
        @csrf @method('PUT')
        <div class="form-group"><label for="provider-mode">{{ __('module-donations::messages.provider_mode') }}</label>
            <select name="mode" id="provider-mode" @disabled(request()->attributes->get('admin_read_only'))>
                @if($state['supports_test'])<option value="test" @selected($state['mode']==='test')>{{ __('module-donations::messages.provider_test') }}</option>@endif
                <option value="live" @selected($state['mode']==='live')>{{ __('module-donations::messages.provider_live') }}</option>
            </select>
        </div>
        <h3>{{ __('module-donations::messages.provider_currencies') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.provider_currencies_help') }}</p>
        @forelse($availableCurrencies as $code)
            <label class="settings-remove-row"><input type="checkbox" name="currency_codes[]" value="{{ $code }}" @checked(in_array($code, $state['currency_codes'], true)) @disabled(request()->attributes->get('admin_read_only'))> {{ $code }}</label>
        @empty
            <p class="admin-field-help">{{ __('module-donations::messages.provider_no_matching_rates') }}</p>
        @endforelse
        @foreach($fields as $field => $spec)
            <div class="form-group"><label for="provider-field-{{ $field }}">{{ $spec['label'] }}</label>
                <input id="provider-field-{{ $field }}" name="credentials[{{ $field }}]" type="{{ $spec['secret'] ? 'password' : 'text' }}" value="" autocomplete="new-password" maxlength="1024" @disabled(request()->attributes->get('admin_read_only'))>
                <small>{{ in_array($field, $state['configured_fields'], true) ? __('module-donations::messages.provider_field_set') : __('module-donations::messages.provider_field_empty') }}</small>
            </div>
        @endforeach
        <input type="hidden" name="enabled" value="0">
        <label class="settings-remove-row"><input name="enabled" value="1" type="checkbox" @checked($state['enabled']) @disabled(request()->attributes->get('admin_read_only'))> {{ __('module-donations::messages.provider_on') }}</label>
        @unless(request()->attributes->get('admin_read_only'))<button class="button button-primary" type="submit">{{ __('module-donations::messages.save_settings') }}</button>@endunless
    </form>
    @if($driver->code() === 'yoomoney')
        <div class="settings-form" data-testid="donations-live-activation">
            <h3>{{ __('module-donations::messages.activation_title') }}</h3>
            @if($readinessIssues !== [])
                <p class="admin-field-error" role="status">{{ __('module-donations::messages.activation_blocked') }}</p>
                <ul>@foreach($readinessIssues as $issue)<li>{{ __('module-donations::messages.activation_issue_'.$issue) }}</li>@endforeach</ul>
            @endif
            @if($pilotPlayer)<p>{{ __('module-donations::messages.activation_current_player') }}: {{ $pilotPlayer->name }} (#{{ $pilotPlayer->id }})</p>@endif
            @if($errors->has('activation'))<p class="admin-field-error" role="alert">{{ $errors->first('activation') }}</p>@endif
            <form method="GET" action="{{ route('admin.module-pages.donations.providers.show', ['adminPath' => $adminPath, 'providerCode' => 'yoomoney']) }}" class="settings-form">
                <label for="donations-player-search">{{ __('module-donations::messages.activation_search') }}</label>
                <input id="donations-player-search" name="player_search" type="search" value="{{ $playerSearch }}" maxlength="64">
                <button class="button" type="submit">{{ __('module-donations::messages.activation_find') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.module-pages.donations.providers.activation.update', ['adminPath' => $adminPath]) }}" class="settings-form">
                @csrf @method('PUT')
                <label for="donations-payment-mode">{{ __('module-donations::messages.activation_mode') }}</label>
                <select name="payment_mode" id="donations-payment-mode" @disabled(request()->attributes->get('admin_read_only'))>
                    <option value="off" @selected($activation['mode']==='off')>{{ __('module-donations::messages.activation_off') }}</option>
                    <option value="pilot" @selected($activation['mode']==='pilot')>{{ __('module-donations::messages.activation_pilot') }}</option>
                    <option value="live" @selected($activation['mode']==='live')>{{ __('module-donations::messages.activation_live') }}</option>
                </select>
                <label for="donations-pilot-player">{{ __('module-donations::messages.activation_pilot_player') }}</label>
                <select id="donations-pilot-player" name="pilot_user_id" @disabled(request()->attributes->get('admin_read_only'))>
                    <option value="">—</option>
                    @if($pilotPlayer)<option value="{{ $pilotPlayer->id }}" selected>{{ $pilotPlayer->name }} (#{{ $pilotPlayer->id }})</option>@endif
                    @foreach($players as $player)
                        @if(!$pilotPlayer || $pilotPlayer->id !== $player->id)
                            <option value="{{ $player->id }}">{{ $player->name }} (#{{ $player->id }})</option>
                        @endif
                    @endforeach
                </select>
                <p class="admin-field-help">{{ __('module-donations::messages.activation_no_env') }}</p>
                @unless(request()->attributes->get('admin_read_only'))<button class="button button-primary" type="submit">{{ __('module-donations::messages.activation_save') }}</button>@endunless
            </form>
        </div>
    @endif
</section>
@endsection
