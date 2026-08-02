@props([
    'href',
    'active' => false,
])

<a
    href="{{ $href }}"
    {{ $attributes->class(['admin-tab', 'active' => $active]) }}
    @if($active) aria-current="page" @endif
>{{ $slot }}</a>
