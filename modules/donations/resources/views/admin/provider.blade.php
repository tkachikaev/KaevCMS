@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.providers_title'))
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="donations-provider-detail">
    @if($driver->code() === 'yoomoney')
        <h2>ЮMoney</h2>
        <p class="admin-field-help">{{ __('module-donations::messages.yoomoney_simple_intro') }}</p>
        @if($webhookIsLocal)
            <p class="admin-field-error" role="status">{{ __('module-donations::messages.yoomoney_local_warning') }}</p>
        @else
            <div class="form-group">
                <label>{{ __('module-donations::messages.yoomoney_notification_address') }}</label>
                <code class="donations-callback-url">{{ $webhookUrl }}</code>
                <small>{{ __('module-donations::messages.yoomoney_notification_help') }}</small>
            </div>
        @endif
        @if($errors->has('provider'))<p class="admin-field-error" role="alert">{{ $errors->first('provider') }}</p>@endif
        <form method="POST" action="{{ route('admin.module-pages.donations.providers.update', ['adminPath' => $adminPath, 'providerCode' => 'yoomoney']) }}" class="settings-form" autocomplete="off" data-testid="donations-yoomoney-simple-settings">
            @csrf @method('PUT')
            <div class="form-group">
                <label for="yoomoney-wallet-number">{{ __('module-donations::messages.yoomoney_wallet_number') }}</label>
                <input id="yoomoney-wallet-number" name="credentials[receiver]" type="text" inputmode="numeric" value="{{ $publicWalletNumber }}" maxlength="30" @disabled(request()->attributes->get('admin_read_only'))>
                <small>{{ __('module-donations::messages.yoomoney_wallet_public') }}</small>
            </div>
            <div class="form-group">
                <label for="yoomoney-secret">{{ __('module-donations::messages.yoomoney_notification_secret') }}</label>
                <input id="yoomoney-secret" name="credentials[notification_secret]" type="password" value="" placeholder="{{ $hasNotificationSecret ? '••••••••' : __('module-donations::messages.yoomoney_secret_placeholder') }}" autocomplete="new-password" maxlength="1024" @disabled(request()->attributes->get('admin_read_only'))>
                <small>{{ $hasNotificationSecret ? __('module-donations::messages.yoomoney_secret_saved') : __('module-donations::messages.yoomoney_secret_missing') }}</small>
            </div>
            <p class="admin-field-help">{{ __('module-donations::messages.yoomoney_rub_info') }}</p>
            <input type="hidden" name="payment_enabled" value="0">
            <label class="settings-remove-row"><input name="payment_enabled" value="1" type="checkbox" @checked($activation['mode'] !== 'off') @disabled(request()->attributes->get('admin_read_only'))> {{ __('module-donations::messages.yoomoney_accept_payments') }}</label>
            <p class="admin-field-help">{{ __('module-donations::messages.yoomoney_stop_help') }}</p>
            @unless(request()->attributes->get('admin_read_only'))<button class="button button-primary" type="submit">{{ __('module-donations::messages.save_settings') }}</button>@endunless
        </form>
    @else
        <h2>{{ $driver->name() }}</h2>
        @if($errors->has('provider'))<p class="admin-field-error" role="alert">{{ $errors->first('provider') }}</p>@endif
        <form method="POST" action="{{ route('admin.module-pages.donations.providers.update', ['adminPath' => $adminPath, 'providerCode' => $driver->code()]) }}" class="settings-form" autocomplete="off">
            @csrf @method('PUT')
            <h3>{{ __('module-donations::messages.provider_currencies') }}</h3>
            @foreach($availableCurrencies as $code)
                <label class="settings-remove-row"><input type="checkbox" name="currency_codes[]" value="{{ $code }}" @checked(in_array($code, $state['currency_codes'], true)) @disabled(request()->attributes->get('admin_read_only'))> {{ $code }}</label>
            @endforeach
            @foreach($fields as $field => $spec)
                <div class="form-group"><label for="provider-field-{{ $field }}">{{ $spec['label'] }}</label>
                    <input id="provider-field-{{ $field }}" name="credentials[{{ $field }}]" type="{{ $spec['secret'] ? 'password' : 'text' }}" value="" autocomplete="off" maxlength="1024" @disabled(request()->attributes->get('admin_read_only'))>
                </div>
            @endforeach
            <input type="hidden" name="enabled" value="0">
            <label class="settings-remove-row"><input name="enabled" value="1" type="checkbox" @checked($state['enabled']) @disabled(request()->attributes->get('admin_read_only'))> {{ __('module-donations::messages.provider_on') }}</label>
            <select name="mode" @disabled(request()->attributes->get('admin_read_only'))><option value="live" @selected($state['mode']==='live')>{{ __('module-donations::messages.provider_live') }}</option><option value="test" @selected($state['mode']==='test')>{{ __('module-donations::messages.provider_test') }}</option></select>
            @unless(request()->attributes->get('admin_read_only'))<button class="button button-primary" type="submit">{{ __('module-donations::messages.save_settings') }}</button>@endunless
        </form>
    @endif
</section>
@endsection
@push('scripts')
<script src="{{ asset('assets/modules/donations-admin.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
