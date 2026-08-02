@props([
    'label',
    'subtle' => false,
])

<nav {{ $attributes->class([
    'admin-tabs',
    'admin-subtabs' => $subtle,
]) }} aria-label="{{ $label }}">
    {{ $slot }}
</nav>
