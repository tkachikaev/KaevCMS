@extends('admin.layouts.panel')
@section('title', 'PayPal — '. __('module-donations::messages.providers_title'))
@section('description', __('module-donations::messages.provider_paypal_description'))
@section('docs', 'donations-paypal')
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card donations-provider-setup setup-page" data-testid="donations-paypal-settings">
    <header class="setup-head">
        <span class="setup-logo"><img src="{{ asset('assets/modules/paypal.svg') }}" alt="" width="40" height="40"></span>
        <div class="setup-head-copy">
            <h2>PayPal</h2>
            <p class="setup-status" data-status="{{ $settings['enabled'] ? 'live' : 'off' }}" role="status">{{ $settings['enabled'] ? __('module-donations::messages.paypal_live_enabled') : __('module-donations::messages.paypal_live_disabled') }}</p>
        </div>
        <span class="status-badge setup-badge" title="{{ __('module-donations::messages.provider_alpha_help') }}">{{ __('module-donations::messages.provider_alpha') }}</span>
    </header>
    @foreach(['paypal', 'paypal_check', 'paypal_test'] as $errorKey)
        @if($errors->has($errorKey))<p class="admin-field-error" role="alert">{{ $errors->first($errorKey) }}</p>@endif
    @endforeach
    <div class="setup-steps">
    <section class="setup-card donations-provider-address">
        <h3 class="setup-card-title">{{ __('module-donations::messages.provider_card_notify_title') }}</h3>
        <label class="setup-sr" for="paypal-webhook-url">{{ __('module-donations::messages.provider_notification_address') }}</label>
        <div class="setup-copy-row"><input id="paypal-webhook-url" type="text" readonly value="{{ $callbackUrl }}" spellcheck="false" data-donations-select-copy><button type="button" class="button button-secondary" data-copy-target="#paypal-webhook-url" data-copied="{{ __('module-donations::messages.provider_copied') }}">{{ __('module-donations::messages.provider_copy') }}</button><button type="button" class="button button-secondary" data-provider-test-open="paypal" disabled>{{ __('module-donations::messages.provider_test_button') }}</button></div>
        @unless($webhookLive)<p class="setup-warning" role="status">{{ __('module-donations::messages.provider_https_warning') }}</p>@endunless
        <details class="setup-more"><summary>{{ __('module-donations::messages.provider_connection_guide') }}</summary>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_webhook_verify_note') }}</p>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_events_title') }}:</p>
        <ul>@foreach($events as $event)<li><code>{{ $event }}</code></li>@endforeach</ul>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_events_note') }}</p>
        <p><a href="https://developer.paypal.com/dashboard/applications/sandbox" rel="noopener noreferrer" target="_blank">PayPal Developer Dashboard</a></p>
        </details>
    </section>
    <form method="POST" action="{{ route('admin.module-pages.donations.providers.paypal.save', ['adminPath' => $adminPath]) }}" class="settings-form setup-form" autocomplete="off" id="paypal-settings-form" data-testid="paypal-config-form">
        @csrf @method('PUT')
        @php($environment = 'live')
        <section class="setup-card">
            <h3 class="setup-card-title">{{ __('module-donations::messages.provider_card_credentials_title') }}</h3>
            <h4 class="setup-subtitle">{{ __('module-donations::messages.paypal_live_credentials_title') }}</h4>
            <p class="setup-card-help">{{ __('module-donations::messages.paypal_live_merchant_help') }}</p>
            <div class="donations-provider-fields">
                <div class="form-group">
                    <label for="paypal-{{ $environment }}-id">Client ID</label>
                    <input id="paypal-{{ $environment }}-id" type="text" name="credentials[{{ $environment }}_client_id]" maxlength="512" value="{{ $settings[$environment.'_client_id'] }}" autocomplete="off" @disabled(request()->attributes->get('admin_read_only'))>
                </div>
                <div class="form-group">
                    <label for="paypal-{{ $environment }}-secret">Client Secret</label>
                    <input id="paypal-{{ $environment }}-secret" type="password" name="credentials[{{ $environment }}_client_secret]" maxlength="1024" value="" autocomplete="new-password" @disabled(request()->attributes->get('admin_read_only'))>
                    <small class="{{ $settings[$environment.'_secret_saved'] ? 'setup-saved' : '' }}">{{ $settings[$environment.'_secret_saved'] ? __('module-donations::messages.paypal_secret_saved') : __('module-donations::messages.paypal_secret_missing') }}</small>
                </div>
                <div class="form-group">
                    <label for="paypal-{{ $environment }}-webhook">Webhook ID</label>
                    <input id="paypal-{{ $environment }}-webhook" type="password" name="credentials[{{ $environment }}_webhook_id]" maxlength="190" value="" autocomplete="off" @disabled(request()->attributes->get('admin_read_only'))>
                    <small class="{{ $settings[$environment.'_webhook_saved'] ? 'setup-saved' : '' }}">{{ $settings[$environment.'_webhook_saved'] ? __('module-donations::messages.paypal_webhook_saved') : __('module-donations::messages.paypal_webhook_missing') }}</small>
                </div>
                <div class="form-group"><label for="paypal-live-merchant">Merchant ID (PayPal account ID)</label>
                    <input id="paypal-live-merchant" type="text" name="credentials[live_merchant_id]" maxlength="32" value="{{ $settings['live_merchant_id'] }}" autocomplete="off" @disabled(request()->attributes->get('admin_read_only'))>
                </div>
            </div>
        <p class="setup-card-help setup-note">{{ __('module-donations::messages.paypal_sandbox_credentials_note') }}</p>
        </section>
        <section class="setup-card">
            <h3 class="setup-card-title">{{ __('module-donations::messages.provider_card_payments_title') }}</h3>
            <fieldset class="setup-chips">
                <legend>{{ __('module-donations::messages.provider_checkout_currencies') }}</legend>
                <p class="setup-card-help">{{ __('module-donations::messages.paypal_currencies_help') }}</p>
                <div class="setup-chip-list">
                @foreach($currencies as $currency)
                    <label class="setup-chip"><input type="checkbox" name="currency_codes[]" value="{{ $currency }}" @checked(in_array($currency, $settings['currency_codes'], true)) @disabled(request()->attributes->get('admin_read_only'))><span>{{ $currency }}</span></label>
                @endforeach
                </div>
            </fieldset>
        </section>
        <div class="setup-savebar">
            <x-admin.toggle id="paypal-enable-live" name="enable_live" :label="__('module-donations::messages.provider_paypal_accept')" :checked="$settings['enabled']" :disabled="request()->attributes->get('admin_read_only')" />
            @unless(request()->attributes->get('admin_read_only'))<button type="submit" class="button button-primary">{{ __('module-donations::messages.paypal_save_settings') }}</button>@endunless
        </div>
    </form>
    </div>
    <dialog class="donations-provider-test-dialog" data-provider-test-dialog="paypal" aria-labelledby="paypal-test-heading">
        <div class="donations-provider-test-header"><h2 id="paypal-test-heading">{{ __('module-donations::messages.provider_test_paypal') }}</h2><button class="button button-secondary" type="button" data-provider-test-close aria-label="{{ __('module-donations::messages.provider_test_close') }}">×</button></div>
        <div class="donations-provider-test-body">
            <details class="donations-provider-details" data-testid="paypal-sandbox-credentials" @if(!$settings['sandbox_secret_saved']) open @endif>
                <summary>{{ __('module-donations::messages.provider_test_credentials') }}</summary>
        @php($environment = 'sandbox')
            @if($environment === 'live')<p class="admin-field-help">{{ __('module-donations::messages.paypal_live_merchant_help') }}</p>@endif
            <div class="form-group">
                <label for="paypal-{{ $environment }}-id">Client ID</label>
                <input id="paypal-{{ $environment }}-id" type="text" form="paypal-settings-form" name="credentials[{{ $environment }}_client_id]" maxlength="512" value="{{ $settings[$environment.'_client_id'] }}" autocomplete="off" @disabled(request()->attributes->get('admin_read_only'))>
            </div>
            <div class="form-group">
                <label for="paypal-{{ $environment }}-secret">Client Secret</label>
                <input id="paypal-{{ $environment }}-secret" type="password" form="paypal-settings-form" name="credentials[{{ $environment }}_client_secret]" maxlength="1024" value="" autocomplete="new-password" @disabled(request()->attributes->get('admin_read_only'))>
                <small>{{ $settings[$environment.'_secret_saved'] ? __('module-donations::messages.paypal_secret_saved') : __('module-donations::messages.paypal_secret_missing') }}</small>
            </div>
            <div class="form-group">
                <label for="paypal-{{ $environment }}-webhook">Webhook ID</label>
                <input id="paypal-{{ $environment }}-webhook" type="password" form="paypal-settings-form" name="credentials[{{ $environment }}_webhook_id]" maxlength="190" value="" autocomplete="off" @disabled(request()->attributes->get('admin_read_only'))>
                <small>{{ $settings[$environment.'_webhook_saved'] ? __('module-donations::messages.paypal_webhook_saved') : __('module-donations::messages.paypal_webhook_missing') }}</small>
            </div>
            @if($environment === 'live')
                <div class="form-group"><label for="paypal-live-merchant">Merchant ID (PayPal account ID)</label>
                    <input id="paypal-live-merchant" type="text" form="paypal-settings-form" name="credentials[live_merchant_id]" maxlength="32" value="{{ $settings['live_merchant_id'] }}" autocomplete="off" @disabled(request()->attributes->get('admin_read_only'))>
                </div>
            @endif
                @unless(request()->attributes->get('admin_read_only'))<button class="button button-primary" type="submit" form="paypal-settings-form">{{ __('module-donations::messages.paypal_save_settings') }}</button>@endunless
            </details>
    <section class="donations-readiness">
        <h3>{{ __('module-donations::messages.paypal_api_check_title') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_api_check_help') }}</p>
        @unless(request()->attributes->get('admin_read_only'))
            <form method="POST" action="{{ route('admin.module-pages.donations.providers.paypal.check', ['adminPath' => $adminPath]) }}" class="settings-form">
                @csrf
                <div class="form-group"><select name="environment" aria-label="{{ __('module-donations::messages.provider_mode_label') }}"><option value="sandbox">{{ __('module-donations::messages.paypal_check_sandbox') }}</option><option value="live">{{ __('module-donations::messages.paypal_check_live') }}</option></select></div>
                <button type="submit" class="button button-secondary">{{ __('module-donations::messages.paypal_api_check_button') }}</button>
            </form>
        @endunless
    </section>
    <section class="donations-readiness" data-testid="paypal-admin-sandbox">
        <h3>{{ __('module-donations::messages.paypal_test_title') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_test_help') }}</p>
        <div class="setup-result" role="status" aria-live="polite" data-paypal-result
            data-state="{{ $testStatus['state'] }}" data-reference="{{ $testStatus['reference'] }}"
            data-status-url="{{ route('admin.module-pages.donations.providers.paypal.show', ['adminPath' => $adminPath]) }}">
            @foreach(['none', 'pending', 'verified', 'expired', 'error'] as $resultState)
                <div data-test-state="{{ $resultState }}" @if($testStatus['state'] !== $resultState) hidden @endif>
                    <strong>{{ __('module-donations::messages.paypal_result_'.$resultState) }}</strong>
                </div>
            @endforeach
            <small data-test-summary>{{ $testStatus['summary'] }}</small>
        </div>
        @unless(request()->attributes->get('admin_read_only'))
            <form method="POST" action="{{ route('admin.module-pages.donations.providers.paypal.test.start', ['adminPath' => $adminPath]) }}" target="_blank" rel="noopener noreferrer" class="settings-form" data-testid="paypal-test-form">
                @csrf
                @if($settings['currency_codes'] === [])
                    <p class="setup-warning" role="status" data-testid="paypal-no-currency">{{ __('module-donations::messages.provider_test_no_currency') }}</p>
                @endif
                <div class="form-group" @if($settings['currency_codes'] === []) hidden @endif><label for="paypal-test-currency">{{ __('module-donations::messages.freekassa_test_currency') }}</label>
                    <select id="paypal-test-currency" name="currency_code" required>
                        @foreach($settings['currency_codes'] as $currency)
                            <option value="{{ $currency }}">{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group"><label for="paypal-test-amount">{{ __('module-donations::messages.paypal_test_amount') }}</label>
                    <input type="text" name="amount" id="paypal-test-amount" value="1" maxlength="6" inputmode="decimal" required>
                    <small>{{ __('module-donations::messages.paypal_test_jpy') }}</small>
                </div>
                <button type="submit" class="button button-secondary" @disabled(!$settings['sandbox_secret_saved'] || !$settings['sandbox_client_id'] || $settings['currency_codes'] === [])>{{ __('module-donations::messages.paypal_test_start') }}</button>
            </form>
        @endunless
        <details class="donations-provider-details"><summary>{{ __('module-donations::messages.paypal_test_history') }}</summary>

        @forelse($tests as $test)
            <p><code>{{ $test->invoice_id }}</code> — {{ app(\KaevCMS\Modules\Donations\Services\DonationMoney::class)->format((int) $test->gross_amount_minor, (int) $test->exponent) }} {{ $test->currency_code }} — <strong>{{ $test->status }}</strong> ({{ $test->created_at }})
                @if($test->diagnostic_code)<small>{{ $test->diagnostic_code }}</small>@endif
            </p>
        @empty
            <p class="admin-field-help">{{ __('module-donations::messages.paypal_test_empty') }}</p>
        @endforelse
        </details>
    </section>
        </div>
    </dialog>
</section>
@endsection

@push('scripts')
<script src="{{ asset('assets/modules/donations-admin.js') }}?v={{ cms_version() }}&ui=provider-cards" defer data-navigate-once></script>
@endpush
