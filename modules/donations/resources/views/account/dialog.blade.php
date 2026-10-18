@if(! request()->routeIs('modules.donations.index'))
<dialog class="donations-dialog" data-donations-dialog data-url="{{ route('modules.donations.index') }}" aria-labelledby="donations-dialog-title">
    <div class="donations-dialog-shell">
        <header class="donations-dialog-head">
            <div><span class="account-eyebrow">{{ __('My wallet') }}</span><h2 id="donations-dialog-title">{{ __('module-donations::messages.topup_title') }}</h2></div>
            <button type="button" class="donations-dialog-close" data-donations-close aria-label="{{ __('Close') }}">×</button>
        </header>
        <div data-donations-dialog-content aria-live="polite"></div>
        <p class="donations-dialog-loading" data-donations-loading hidden>{{ __('module-donations::messages.topup_loading') }}</p>
        <p class="account-field-error" data-donations-load-error role="alert" hidden>{{ __('module-donations::messages.topup_load_failed') }} <a href="{{ route('modules.donations.index') }}">{{ __('module-donations::messages.topup_open_page') }}</a></p>
    </div>
</dialog>
@endif
