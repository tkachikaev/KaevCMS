@extends('admin.layouts.panel')
@section('title', 'PayPal — '. __('module-donations::messages.providers_title'))
@section('description', __('module-donations::messages.paypal_intro'))
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="donations-paypal-settings">
    <h2>PayPal Orders v2</h2>
    <p class="admin-field-help">{{ __('module-donations::messages.paypal_intro') }}</p>
    <p class="admin-field-help" role="status"><strong>{{ $settings['enabled'] ? __('module-donations::messages.paypal_live_enabled') : __('module-donations::messages.paypal_live_disabled') }}</strong></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @foreach(['paypal', 'paypal_check', 'paypal_test'] as $errorKey)
        @if($errors->has($errorKey))<p class="admin-field-error" role="alert">{{ $errors->first($errorKey) }}</p>@endif
    @endforeach
    <form method="POST" action="{{ route('admin.module-pages.donations.providers.paypal.save', ['adminPath' => $adminPath]) }}" class="settings-form" autocomplete="off" data-testid="paypal-config-form">
        @csrf @method('PUT')
        <h3>{{ __('module-donations::messages.provider_currencies') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_currencies_help') }}</p>
        <div class="donations-provider-currencies">
            @foreach($currencies as $currency)
                <label class="settings-remove-row"><input type="checkbox" name="currency_codes[]" value="{{ $currency }}" @checked(in_array($currency, $settings['currency_codes'], true)) @disabled(request()->attributes->get('admin_read_only'))> {{ $currency }}</label>
            @endforeach
        </div>
        @foreach(['sandbox', 'live'] as $environment)
            <h3>{{ $environment === 'sandbox' ? 'Sandbox' : 'Live' }}</h3>
            @if($environment === 'live')<p class="admin-field-help">{{ __('module-donations::messages.paypal_live_merchant_help') }}</p>@endif
            <div class="form-group">
                <label for="paypal-{{ $environment }}-id">Client ID</label>
                <input id="paypal-{{ $environment }}-id" type="text" name="credentials[{{ $environment }}_client_id]" maxlength="512" value="{{ $settings[$environment.'_client_id'] }}" autocomplete="off" @disabled(request()->attributes->get('admin_read_only'))>
            </div>
            <div class="form-group">
                <label for="paypal-{{ $environment }}-secret">Client Secret</label>
                <input id="paypal-{{ $environment }}-secret" type="password" name="credentials[{{ $environment }}_client_secret]" maxlength="1024" value="" autocomplete="new-password" @disabled(request()->attributes->get('admin_read_only'))>
                <small>{{ $settings[$environment.'_secret_saved'] ? __('module-donations::messages.paypal_secret_saved') : __('module-donations::messages.paypal_secret_missing') }}</small>
            </div>
            <div class="form-group">
                <label for="paypal-{{ $environment }}-webhook">Webhook ID</label>
                <input id="paypal-{{ $environment }}-webhook" type="password" name="credentials[{{ $environment }}_webhook_id]" maxlength="190" value="" autocomplete="off" @disabled(request()->attributes->get('admin_read_only'))>
                <small>{{ $settings[$environment.'_webhook_saved'] ? __('module-donations::messages.paypal_webhook_saved') : __('module-donations::messages.paypal_webhook_missing') }}</small>
            </div>
            @if($environment === 'live')
                <div class="form-group"><label for="paypal-live-merchant">Merchant ID (PayPal account ID)</label>
                    <input id="paypal-live-merchant" type="text" name="credentials[live_merchant_id]" maxlength="32" value="{{ $settings['live_merchant_id'] }}" autocomplete="off" @disabled(request()->attributes->get('admin_read_only'))>
                </div>
            @endif
        @endforeach
        <label class="settings-remove-row"><input type="checkbox" name="enable_live" value="1" @checked($settings['enabled']) @disabled(request()->attributes->get('admin_read_only'))> {{ __('module-donations::messages.paypal_enable_live') }}</label>
        @unless(request()->attributes->get('admin_read_only'))<button type="submit" class="button button-primary">{{ __('module-donations::messages.save_settings') }}</button>@endunless
    </form>
    <section class="donations-readiness">
        <h3>{{ __('module-donations::messages.paypal_webhook_title') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_webhook_help') }}</p>
        <div class="form-group"><label for="paypal-webhook-url">URL</label>
            <input id="paypal-webhook-url" type="text" readonly value="{{ $callbackUrl }}" spellcheck="false">
        </div>
        @unless($webhookLive)<p class="admin-field-error">{{ __('module-donations::messages.paypal_https_warning') }}</p>@endunless
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_webhook_verify_note') }}</p>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_events_title') }}:</p>
        <ul>@foreach($events as $event)<li><code>{{ $event }}</code></li>@endforeach</ul>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_events_note') }}</p>
        <p><a href="https://developer.paypal.com/dashboard/applications/sandbox" rel="noopener noreferrer" target="_blank">PayPal Developer Dashboard</a></p>
    </section>
    <section class="donations-readiness">
        <h3>{{ __('module-donations::messages.paypal_api_check_title') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_api_check_help') }}</p>
        @unless(request()->attributes->get('admin_read_only'))
            <form method="POST" action="{{ route('admin.module-pages.donations.providers.paypal.check', ['adminPath' => $adminPath]) }}" class="settings-form">
                @csrf
                <select name="environment" aria-label="PayPal environment"><option value="sandbox">Sandbox</option><option value="live">Live</option></select>
                <button type="submit" class="button button-secondary">{{ __('module-donations::messages.paypal_api_check_button') }}</button>
            </form>
        @endunless
    </section>
    <section class="donations-readiness" data-testid="paypal-admin-sandbox">
        <h3>{{ __('module-donations::messages.paypal_test_title') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_test_help') }}</p>
        @unless(request()->attributes->get('admin_read_only'))
            <form method="POST" action="{{ route('admin.module-pages.donations.providers.paypal.test.start', ['adminPath' => $adminPath]) }}" target="_blank" rel="noopener noreferrer" class="settings-form" data-testid="paypal-test-form">
                @csrf
                <div class="form-group"><label for="paypal-test-currency">{{ __('module-donations::messages.freekassa_test_currency') }}</label>
                    <select id="paypal-test-currency" name="currency_code" required>
                        @foreach($settings['currency_codes'] as $currency)
                            <option value="{{ $currency }}">{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group"><label for="paypal-test-amount">{{ __('module-donations::messages.freekassa_test_amount') }}</label>
                    <input type="text" name="amount" id="paypal-test-amount" value="1.00" maxlength="6" inputmode="decimal" required>
                    <small>{{ __('module-donations::messages.paypal_test_jpy') }}</small>
                </div>
                <button type="submit" class="button button-secondary" @disabled(!$settings['sandbox_secret_saved'] || !$settings['sandbox_client_id'] || $settings['currency_codes'] === [])>{{ __('module-donations::messages.paypal_test_start') }}</button>
            </form>
        @endunless
        <h3>{{ __('module-donations::messages.paypal_test_history') }}</h3>
        @forelse($tests as $test)
            <p><code>{{ $test->invoice_id }}</code> — {{ app(\KaevCMS\Modules\Donations\Services\DonationMoney::class)->format((int) $test->gross_amount_minor, (int) $test->exponent) }} {{ $test->currency_code }} — <strong>{{ $test->status }}</strong> ({{ $test->created_at }})
                @if($test->diagnostic_code)<small>{{ $test->diagnostic_code }}</small>@endif
            </p>
        @empty
            <p class="admin-field-help">{{ __('module-donations::messages.paypal_test_empty') }}</p>
        @endforelse
    </section>
</section>
@endsection
