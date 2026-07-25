@props([
    'user',
    'fallback' => null,
])
@php
    $avatarUrl = app(\App\Services\Account\AccountAvatarCatalog::class)->url($user->avatar_filename);
    $fallbackValue = is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : (string) $user->name;
    $fallbackLetter = mb_strtoupper(mb_substr($fallbackValue, 0, 1));
@endphp
<span {{ $attributes->merge(['class' => 'account-avatar']) }} data-account-avatar>
    @if($avatarUrl !== null)
        <img src="{{ $avatarUrl }}" alt="" loading="lazy" decoding="async">
    @else
        <span aria-hidden="true">{{ $fallbackLetter }}</span>
    @endif
</span>
