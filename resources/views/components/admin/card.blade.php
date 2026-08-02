@props([
    'narrow' => false,
    'muted' => false,
])

<section {{ $attributes->class([
    'admin-card',
    'form-card',
    'settings-narrow-card' => $narrow,
    'form-card-muted' => $muted,
]) }}>
    {{ $slot }}
</section>
