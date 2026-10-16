@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.providers_title'))
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="donations-provider-detail">
    <h2>{{ $driver->name() }} ({{ $driver->code() }})</h2>
    <p class="admin-field-help">{{ __('module-donations::messages.provider_not_payment_ready') }}</p>
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
</section>
@endsection
