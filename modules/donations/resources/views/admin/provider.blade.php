@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.providers_title'))
@section('description', __('module-donations::messages.providers_admin_description'))
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="donations-provider-detail">
    @if($driver->code() === 'yoomoney')
        <h2>ЮMoney</h2>
        <p class="admin-field-help">{{ __('module-donations::messages.yoomoney_simple_intro') }}</p>
        @if($webhookIsLocal && ! in_array('https', $readinessIssues, true))
            <p class="admin-field-error" role="status">{{ __('module-donations::messages.yoomoney_local_warning') }}</p>
        @elseif(! $webhookIsLocal)
            <div class="form-group">
                <label>{{ __('module-donations::messages.yoomoney_notification_address') }}</label>
                <div class="donations-callback-row">
                    <code class="donations-callback-url">{{ $webhookUrl }}</code>
                    @unless(request()->attributes->get('admin_read_only'))
                        <button class="button button-secondary donations-test-open" type="button" data-yoomoney-test-open
                            data-start-url="{{ route('admin.module-pages.donations.providers.notification-test.start', ['adminPath' => $adminPath]) }}"
                            data-status-url="{{ route('admin.module-pages.donations.providers.notification-test.status', ['adminPath' => $adminPath, 'token' => str_repeat('0', 40)]) }}">{{ __('module-donations::messages.yoomoney_test_button') }}</button>
                    @endunless
                </div>
                <small>{{ __('module-donations::messages.yoomoney_notification_help') }}</small>
            </div>
            @unless(request()->attributes->get('admin_read_only'))
                {{-- Waits for the test notification sent from YooMoney's settings; on success the
                     settings form below is saved as with its own button. --}}
                <dialog class="donations-test-dialog" data-yoomoney-test-dialog data-state="waiting" aria-labelledby="yoomoney-test-title">
                    <div class="donations-test-card">
                        <div class="donations-test-mark" aria-hidden="true">
                            <span class="donations-test-spinner"></span>
                            <svg class="donations-test-ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                            <svg class="donations-test-fail" viewBox="0 0 24 24"><path d="M7 7l10 10M17 7 7 17"/></svg>
                        </div>
                        <div class="donations-test-copy" data-test-copy="waiting">
                            <h2 id="yoomoney-test-title">{{ __('module-donations::messages.yoomoney_test_waiting_title') }}</h2>
                            <p>{{ __('module-donations::messages.yoomoney_test_waiting_text') }}</p>
                            <small>{{ __('module-donations::messages.yoomoney_test_waiting_hint') }}</small>
                        </div>
                        <div class="donations-test-copy" data-test-copy="received" hidden>
                            <h2>{{ __('module-donations::messages.yoomoney_test_received_title') }}</h2>
                            <p>{{ __('module-donations::messages.yoomoney_test_received_text') }}</p>
                        </div>
                        <div class="donations-test-copy" data-test-copy="signature_mismatch" hidden>
                            <h2>{{ __('module-donations::messages.yoomoney_test_mismatch_title') }}</h2>
                            <p>{{ __('module-donations::messages.yoomoney_test_mismatch_text') }}</p>
                            <small data-test-still-waiting hidden>{{ __('module-donations::messages.yoomoney_test_mismatch_waiting_hint') }}</small>
                        </div>
                        <div class="donations-test-copy" data-test-copy="expired" hidden>
                            <h2>{{ __('module-donations::messages.yoomoney_test_expired_title') }}</h2>
                            <p>{{ __('module-donations::messages.yoomoney_test_expired_text') }}</p>
                        </div>
                        <div class="donations-test-copy" data-test-copy="error" hidden>
                            <h2>{{ __('module-donations::messages.yoomoney_test_error_title') }}</h2>
                            <p data-test-error>{{ __('module-donations::messages.yoomoney_test_secret_required') }}</p>
                        </div>
                        <div class="donations-test-actions">
                            <button class="button button-secondary" type="button" data-yoomoney-test-close>{{ __('module-donations::messages.yoomoney_test_close') }}</button>
                            <button class="button button-primary" type="button" data-yoomoney-test-retry hidden>{{ __('module-donations::messages.yoomoney_test_retry') }}</button>
                        </div>
                    </div>
                </dialog>
            @endunless
        @endif
        @if($errors->has('provider'))<p class="admin-field-error" role="alert">{{ $errors->first('provider') }}</p>@endif
        @if($readinessIssues !== [])
            <div class="donations-readiness" role="status" data-testid="donations-payment-blockers">
                <h3>{{ __('module-donations::messages.yoomoney_blockers_title') }}</h3>
                @if(app()->environment('local'))<p>{{ __('module-donations::messages.yoomoney_local_preview') }}</p>@endif
                <ul>
                    @foreach($readinessIssues as $issue)
                        @php($reason = $issue === 'provider' ? match ($diagnostic['state']) {
                            'no_active_rate' => 'rate',
                            'invalid', 'not_configured' => 'credentials',
                            'disabled' => 'disabled',
                            default => 'provider',
                        } : ($issue === 'environment' && app()->environment('production') ? 'debug' : $issue))
                        <li data-payment-blocker="{{ $reason }}">{{ __('module-donations::messages.yoomoney_blocker_'.$reason) }}
                            @if($reason === 'rate')
                                <a wire:navigate href="{{ route('admin.module-pages.donations.index', ['adminPath' => $adminPath]) }}">{{ __('module-donations::messages.yoomoney_configure_price') }}</a>
                            @endif
                        </li>
                        @if($issue === 'environment' && ! app()->environment('production') && config('app.debug') !== false)
                            <li data-payment-blocker="debug">{{ __('module-donations::messages.yoomoney_blocker_debug') }}</li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endif
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
        @if($driver->code() === 'freekassa')
            <p class="admin-field-help" role="status">{{ __('module-donations::messages.freekassa_iteration2_warning') }}</p>
            <div class="form-group">
                <label for="freekassa-callback-url">{{ __('module-donations::messages.freekassa_notification_address') }}</label>
                <input id="freekassa-callback-url" class="donations-callback-url" type="text" readonly value="{{ $webhookUrl }}" onclick="this.select()" aria-label="{{ __('module-donations::messages.freekassa_notification_address') }}">
                <small>{{ __('module-donations::messages.freekassa_callback_hint') }}</small>
            </div>
            @if($webhookIsLocal)
                <p class="admin-field-error" role="status">{{ __('module-donations::messages.freekassa_https_warning') }}</p>
            @endif
            @if($freeKassaPolicyError)
                <p class="admin-field-error" role="alert">{{ __('module-donations::messages.freekassa_policy_corrupt') }}</p>
            @endif
            <p class="admin-field-help">{{ __('module-donations::messages.freekassa_merchant_warning') }}</p>
            <div class="donations-readiness" role="status" data-testid="freekassa-local-diagnostic">
                <h3>{{ __('module-donations::messages.freekassa_local_diagnostic_title') }}</h3>
                <p>{{ __('module-donations::messages.freekassa_diagnostic_'.$diagnostic['state']) }}</p>
                <p>{{ __('module-donations::messages.freekassa_diagnostic_ip_count', ['count' => count($freeKassaPolicy['senders'])]) }}</p>
                <p>{{ __('module-donations::messages.freekassa_diagnostic_proxy_count', ['count' => count($freeKassaPolicy['proxies'])]) }}</p>
                <p class="admin-field-help">{{ __('module-donations::messages.freekassa_diagnostic_not_remote') }}</p>
            </div>
        @endif
        @if($errors->has('provider'))<p class="admin-field-error" role="alert">{{ $errors->first('provider') }}</p>@endif
        <form method="POST" action="{{ route('admin.module-pages.donations.providers.update', ['adminPath' => $adminPath, 'providerCode' => $driver->code()]) }}" class="settings-form" autocomplete="off">
            @csrf @method('PUT')
            <h3>{{ __('module-donations::messages.provider_currencies') }}</h3>
            @foreach($availableCurrencies as $code)
                <label class="settings-remove-row"><input type="checkbox" name="currency_codes[]" value="{{ $code }}" @checked(in_array($code, $state['currency_codes'], true)) @disabled(request()->attributes->get('admin_read_only'))> {{ $code }}</label>
            @endforeach
            @foreach($fields as $field => $spec)
                <div class="form-group"><label for="provider-field-{{ $field }}">{{ $driver->code() === 'freekassa' ? __('module-donations::messages.freekassa_field_'.$field) : $spec['label'] }}</label>
                    <input id="provider-field-{{ $field }}" name="credentials[{{ $field }}]" type="{{ $spec['secret'] ? 'password' : 'text' }}" value="{{ $driver->code() === 'freekassa' && $field === 'merchant_id' ? $publicMerchantId : '' }}" autocomplete="off" maxlength="1024" @disabled(request()->attributes->get('admin_read_only'))>
                    @if(in_array($field, $state['configured_fields'], true))
                        <small>{{ __('module-donations::messages.freekassa_field_saved') }}</small>
                    @endif
                </div>
            @endforeach
            @if($driver->code() === 'freekassa')
                <h3>{{ __('module-donations::messages.freekassa_security_title') }}</h3>
                <p class="admin-field-help">{{ __('module-donations::messages.freekassa_sender_help') }} <a href="https://docs.freekassa.net/" target="_blank" rel="noopener noreferrer">{{ __('module-donations::messages.freekassa_official_docs') }}</a></p>
                <div class="form-group">
                    <label for="freekassa-sender-ips">{{ __('module-donations::messages.freekassa_sender_label') }}</label>
                    <textarea id="freekassa-sender-ips" data-testid="freekassa-sender-ips" name="callback_sender_ips" rows="4" spellcheck="false" maxlength="8192" @disabled(request()->attributes->get('admin_read_only'))>{{ implode("\n", $freeKassaPolicy['senders']) }}</textarea>
                    <small>{{ $freeKassaPolicy['customized'] ? __('module-donations::messages.freekassa_custom_list') : __('module-donations::messages.freekassa_initial_list') }}</small>
                </div>
                <details>
                    <summary>{{ __('module-donations::messages.freekassa_proxy_title') }}</summary>
                    <p class="admin-field-help">{{ __('module-donations::messages.freekassa_proxy_help') }}</p>
                    <div class="form-group">
                        <label for="freekassa-proxy-ips">{{ __('module-donations::messages.freekassa_proxy_label') }}</label>
                        <textarea id="freekassa-proxy-ips" name="callback_proxy_ips" rows="3" spellcheck="false" maxlength="8192" @disabled(request()->attributes->get('admin_read_only'))>{{ implode("\n", $freeKassaPolicy['proxies']) }}</textarea>
                    </div>
                </details>
                <p class="admin-field-help">{{ __('module-donations::messages.freekassa_sender_warning') }}</p>
            @endif
            <input type="hidden" name="enabled" value="0">
            <label class="settings-remove-row"><input name="enabled" value="1" type="checkbox" @checked($state['enabled']) @disabled(request()->attributes->get('admin_read_only'))> {{ __('module-donations::messages.provider_on') }}</label>
            @if($driver->code() === 'freekassa')
                <label for="freekassa-mode">{{ __('module-donations::messages.freekassa_mode_label') }}</label>
                <select id="freekassa-mode" name="mode" @disabled(request()->attributes->get('admin_read_only'))>
                    <option value="test" @selected($state['mode'] === 'test')>{{ __('module-donations::messages.provider_test') }}</option>
                    <option value="live" @selected($state['mode'] === 'live')>{{ __('module-donations::messages.provider_live') }}</option>
                </select>
                <p class="admin-field-help">{{ __('module-donations::messages.freekassa_live_warning') }}</p>
            @else
                <select name="mode" @disabled(request()->attributes->get('admin_read_only'))><option value="live" @selected($state['mode']==='live')>{{ __('module-donations::messages.provider_live') }}</option><option value="test" @selected($state['mode']==='test')>{{ __('module-donations::messages.provider_test') }}</option></select>
            @endif
            @unless(request()->attributes->get('admin_read_only'))<button class="button button-primary" type="submit">{{ __('module-donations::messages.save_settings') }}</button>@endunless
        </form>
        @if($driver->code() === 'freekassa')
            <section class="donations-readiness" data-testid="freekassa-global-activation">
                <h3>{{ __('module-donations::messages.freekassa_global_activation_title') }}</h3>
                <p class="admin-field-help">{{ __('module-donations::messages.freekassa_global_activation_help') }}</p>
                @if($errors->has('activation'))<p class="admin-field-error" role="alert">{{ $errors->first('activation') }}</p>@endif
                <form method="POST" action="{{ route('admin.module-pages.donations.providers.activation.update', ['adminPath' => $adminPath]) }}" class="settings-form">
                    @csrf @method('PUT')
                    <input type="hidden" name="return_provider" value="freekassa">
                    <select name="payment_mode" @disabled(request()->attributes->get('admin_read_only'))>
                        <option value="off" @selected($activation['mode'] === 'off')>{{ __('module-donations::messages.freekassa_global_off') }}</option>
                        <option value="live" @selected($activation['mode'] === 'live')>{{ __('module-donations::messages.freekassa_global_live') }}</option>
                    </select>
                    @unless(request()->attributes->get('admin_read_only'))
                        <button class="button button-secondary" type="submit">{{ __('module-donations::messages.save_settings') }}</button>
                    @endunless
                </form>
                @if($readinessIssues !== [] && $activation['mode'] !== 'live')
                    <p class="admin-field-help">{{ __('module-donations::messages.freekassa_global_blocked') }}</p>
                @endif
            </section>
            <section class="donations-readiness" data-testid="freekassa-admin-test">
                <h3>{{ __('module-donations::messages.freekassa_test_title') }}</h3>
                <p class="admin-field-help">{{ __('module-donations::messages.freekassa_test_external_warning') }}</p>
                <p class="admin-field-help">{{ __('module-donations::messages.freekassa_test_zero_coins') }}</p>
                @if($errors->has('freekassa_test'))<p class="admin-field-error" role="alert">{{ $errors->first('freekassa_test') }}</p>@endif
                @unless(request()->attributes->get('admin_read_only'))
                    <form method="POST" action="{{ route('admin.module-pages.donations.providers.freekassa.test-purchase', ['adminPath' => $adminPath]) }}" target="_blank" rel="noopener noreferrer" class="settings-form" data-testid="freekassa-test-purchase-form">
                        @csrf
                        <div class="form-group"><label for="freekassa-test-currency">{{ __('module-donations::messages.freekassa_test_currency') }}</label>
                            <select id="freekassa-test-currency" name="currency_code" required>
                                @foreach($state['currency_codes'] as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group"><label for="freekassa-test-amount">{{ __('module-donations::messages.freekassa_test_amount') }}</label>
                            <input id="freekassa-test-amount" name="amount" type="text" inputmode="decimal" value="1.00" maxlength="6" required>
                        </div>
                        <button class="button button-secondary" type="submit" @disabled(!$state['enabled'] || $state['mode'] !== 'test')>{{ __('module-donations::messages.freekassa_test_start') }}</button>
                    </form>
                @endunless
                <h3>{{ __('module-donations::messages.freekassa_test_history') }}</h3>
                @forelse($freeKassaTests as $test)
                    <p><code>{{ $test->reference }}</code> — {{ app(\KaevCMS\Modules\Donations\Services\DonationMoney::class)->format((int) $test->amount_minor, 2) }} {{ $test->currency_code }} —
                        {{ $test->status === 'verified' ? __('module-donations::messages.freekassa_test_verified') : (\Carbon\Carbon::parse($test->expires_at)->isPast() ? __('module-donations::messages.freekassa_test_expired') : __('module-donations::messages.freekassa_test_pending')) }}
                        ({{ $test->created_at }})</p>
                @empty
                    <p class="admin-field-help">{{ __('module-donations::messages.freekassa_test_empty') }}</p>
                @endforelse
                <p class="admin-field-help">{{ __('module-donations::messages.freekassa_test_refresh') }}</p>
            </section>
        @endif
    @endif
</section>
@endsection
@push('scripts')
<script src="{{ asset('assets/modules/donations-admin.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
