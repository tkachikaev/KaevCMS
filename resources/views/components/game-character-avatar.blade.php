@props(['character'])
@php
    $characterName = trim((string) ($character['name'] ?? ''));
    $avatarUrl = is_string($character['avatar_url'] ?? null) && trim($character['avatar_url']) !== ''
        ? trim($character['avatar_url'])
        : null;
    $initial = $characterName !== '' ? mb_strtoupper(mb_substr($characterName, 0, 1)) : '?';
@endphp
<span
    {{ $attributes->merge(['aria-hidden' => 'true']) }}
    data-character-avatar
    data-character-race="{{ $character['race_key'] ?? 'unknown' }}"
    data-character-gender="{{ $character['gender_key'] ?? 'neutral' }}"
    data-character-archetype="{{ $character['archetype'] ?? 'default' }}"
>
    @if($avatarUrl !== null)
        <img src="{{ $avatarUrl }}" alt="" loading="lazy" decoding="async">
    @else
        <span>{{ $initial }}</span>
    @endif
</span>
