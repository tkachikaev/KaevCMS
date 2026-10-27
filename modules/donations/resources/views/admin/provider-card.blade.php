@php($logo = match ($code) {
    'yoomoney' => 'yoomoney.svg',
    'freekassa' => 'freekassa.png',
    'paypal' => 'paypal.svg',
    default => null,
})
<x-admin.card class="donations-provider-card" data-provider="{{ $code }}">
    <div class="donations-provider-heading">
        <span class="donations-provider-logo" aria-hidden="true">
            @if($logo)
                <img src="{{ asset('assets/modules/'.$logo) }}" width="40" height="40" alt="">
            @else
                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h4"/></svg>
            @endif
        </span>
        <div><h2>{{ $name }}</h2></div>
    </div>
    <p class="donations-provider-description">{{ $description }}</p>
    <div class="donations-provider-badges">
        <span @class(['status-badge', 'status-badge-success' => $accepting])>{{ $accepting ? __('module-donations::messages.provider_on') : __('module-donations::messages.provider_off') }}</span>
    </div>
    <a class="button button-secondary" wire:navigate href="{{ $settingsUrl }}">{{ __('module-donations::messages.provider_configure') }}</a>
</x-admin.card>
