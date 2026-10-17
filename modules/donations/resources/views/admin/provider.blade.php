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
        <details class="donations-provider-advanced" id="donations-provider-advanced">
            <summary>{{ __('module-donations::messages.provider_advanced') }}</summary>
            <x-admin.field for="provider-mode" :label="__('module-donations::messages.provider_mode')" :hint="__('module-donations::messages.provider_mode_help')">
                <select name="mode" id="provider-mode" @disabled(request()->attributes->get('admin_read_only'))>
                    @if($state['supports_test'])<option value="test" @selected($state['mode']==='test')>{{ __('module-donations::messages.provider_test') }}</option>@endif
                    <option value="live" @selected($state['mode']==='live')>{{ __('module-donations::messages.provider_live') }}</option>
                </select>
            </x-admin.field>
        </details>
        @unless(request()->attributes->get('admin_read_only'))<button class="button button-primary" type="submit">{{ __('module-donations::messages.save_settings') }}</button>@endunless
    </form>
    @if($driver->code() === 'yoomoney')
        <div class="settings-form" data-testid="donations-live-activation">
            <h3>{{ __('module-donations::messages.activation_title') }}</h3>
            @if($readinessIssues !== [])
                <p class="admin-field-error" role="status">{{ __('module-donations::messages.activation_blocked') }}</p>
                <ul>@foreach($readinessIssues as $issue)<li>{{ __('module-donations::messages.activation_issue_'.$issue) }}</li>@endforeach</ul>
            @endif
            @if($errors->has('activation'))<p class="admin-field-error" role="alert">{{ $errors->first('activation') }}</p>@endif
            @php($paymentMode = request()->query('payment_mode') === 'pilot' ? 'pilot' : $activation['mode'])
            <form id="donations-pilot-search-form" method="GET" action="{{ route('admin.module-pages.donations.providers.show', ['adminPath' => $adminPath, 'providerCode' => 'yoomoney']) }}" hidden>
                <input type="hidden" name="payment_mode" value="pilot">
            </form>
            <form method="POST" action="{{ route('admin.module-pages.donations.providers.activation.update', ['adminPath' => $adminPath]) }}" class="settings-form donations-activation-form" data-donations-activation>
                @csrf @method('PUT')
                <x-admin.field for="donations-payment-mode" :label="__('module-donations::messages.activation_mode')">
                    <select name="payment_mode" id="donations-payment-mode" @disabled(request()->attributes->get('admin_read_only'))>
                        @foreach(['off', 'pilot', 'live'] as $mode)
                            <option value="{{ $mode }}" @selected($paymentMode === $mode)>{{ __('module-donations::messages.activation_'.$mode) }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                @foreach(['off', 'pilot', 'live'] as $mode)
                    <p class="admin-field-help" data-donations-mode-help="{{ $mode }}" @if($paymentMode !== $mode) hidden @endif>{{ __('module-donations::messages.activation_help_'.$mode) }}</p>
                @endforeach
                <div class="donations-pilot-controls" data-donations-pilot-controls @if($paymentMode !== 'pilot') hidden @endif>
                    <div class="donations-player-search">
                        <x-admin.field for="donations-player-search" :label="__('module-donations::messages.activation_search')">
                            <input id="donations-player-search" name="player_search" form="donations-pilot-search-form" type="search" value="{{ $playerSearch }}" maxlength="64" @disabled(request()->attributes->get('admin_read_only'))>
                        </x-admin.field>
                        @unless(request()->attributes->get('admin_read_only'))<button class="button button-secondary" type="submit" form="donations-pilot-search-form">{{ __('module-donations::messages.activation_find') }}</button>@endunless
                    </div>
                    <x-admin.field for="donations-pilot-player" :label="__('module-donations::messages.activation_pilot_player')">
                        <select id="donations-pilot-player" name="pilot_user_id" @required($paymentMode === 'pilot') @disabled(request()->attributes->get('admin_read_only'))>
                            <option value="">{{ __('module-donations::messages.activation_select_player') }}</option>
                            @if($pilotPlayer)<option value="{{ $pilotPlayer->id }}" selected>{{ $pilotPlayer->name }} (#{{ $pilotPlayer->id }})</option>@endif
                            @foreach($players as $player)
                                @if(!$pilotPlayer || $pilotPlayer->id !== $player->id)
                                    <option value="{{ $player->id }}">{{ $player->name }} (#{{ $player->id }})</option>
                                @endif
                            @endforeach
                        </select>
                    </x-admin.field>
                </div>
                <p class="admin-field-help">{{ __('module-donations::messages.activation_no_env') }}</p>
                @unless(request()->attributes->get('admin_read_only'))<button class="button button-primary" type="submit">{{ __('module-donations::messages.activation_save') }}</button>@endunless
            </form>
        </div>
    @endif
</section>
@endsection
@push('scripts')
<script src="{{ asset('assets/modules/donations-admin.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
