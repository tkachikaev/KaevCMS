@props(['url' => null])
@if ($url)
    <img src="{{ $url }}" alt="" loading="lazy" width="32" height="32" class="account-wallet-coin-image">
@else
    <svg class="account-wallet-default-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="5.5" ry="7"/><path d="M12 6.5v11M14.5 9H11a2 2 0 0 0 0 4h2a2 2 0 0 1 0 4H9.5"/></svg>
@endif
