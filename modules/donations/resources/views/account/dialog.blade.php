@if(! request()->routeIs('modules.donations.index'))
<dialog class="donations-dialog" data-donations-dialog data-url="{{ route('modules.donations.index') }}" aria-labelledby="donations-dialog-title">
    <div class="donations-dialog-shell account-scroll-area">
        <header class="donations-dialog-head">
            <div><span class="account-eyebrow">{{ __('My wallet') }}</span><h2 id="donations-dialog-title">{{ __('module-donations::messages.topup_title') }}</h2></div>
            <button type="button" class="donations-dialog-close" data-donations-close aria-label="{{ __('Close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </header>
        <div data-donations-dialog-content aria-live="polite"></div>
        <p class="donations-dialog-loading" data-donations-loading hidden>{{ __('module-donations::messages.topup_loading') }}</p>
        <p class="account-field-error" data-donations-load-error role="alert" hidden>{{ __('module-donations::messages.topup_load_failed') }} <a href="{{ route('modules.donations.index') }}">{{ __('module-donations::messages.topup_open_page') }}</a></p>
    </div>
</dialog>
@endif
@if(! request()->routeIs('modules.donations.exchange.index') && collect(app(\App\Support\Modules\ModuleNavigationRegistry::class)->walletLinks())->contains('route', 'modules.donations.exchange.index'))
<dialog class="donations-dialog donations-exchange-dialog" data-donations-dialog data-kind="exchange" data-url="{{ route('modules.donations.exchange.index') }}" aria-labelledby="exchange-dialog-title">
    <div class="donations-dialog-shell account-scroll-area">
        <header class="donations-dialog-head">
            <div><span class="account-eyebrow">{{ __('My wallet') }}</span><h2 id="exchange-dialog-title">{{ __('module-donations::messages.exchange_title') }}</h2></div>
            <button type="button" class="donations-dialog-close" data-donations-close aria-label="{{ __('Close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </header>
        <div data-donations-dialog-content aria-live="polite"></div>
        <p class="donations-dialog-loading" data-donations-loading hidden>{{ __('module-donations::messages.exchange_loading') }}</p>
        <p class="account-field-error" data-donations-load-error role="alert" hidden>{{ __('module-donations::messages.exchange_load_failed') }} <a href="{{ route('modules.donations.exchange.index') }}" data-donations-fallback>{{ __('module-donations::messages.topup_open_page') }}</a></p>
    </div>
</dialog>
@endif
