@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'secondary',
    'compact' => false,
    'disabled' => false,
])

@php
    $classes = [
        'button',
        'button-'.$variant,
        'button-compact' => $compact,
        'disabled' => $disabled,
    ];
@endphp

@if(filled($href))
    <a
        href="{{ $disabled ? '#' : $href }}"
        {{ $attributes->class($classes)->merge([
            'aria-disabled' => $disabled ? 'true' : null,
            'tabindex' => $disabled ? '-1' : null,
        ]) }}
    >{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }} @disabled($disabled)>{{ $slot }}</button>
@endif
