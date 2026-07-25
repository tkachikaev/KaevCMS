@props([
    'large' => false,
])
<span {{ $attributes->class(['game-account-icon', 'large' => $large]) }} data-game-account-icon>
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <rect x="4" y="5" width="16" height="14" rx="4"></rect>
        <path d="M8 12h4M10 10v4M16.5 10.5h.01M18 13h.01"></path>
    </svg>
</span>
