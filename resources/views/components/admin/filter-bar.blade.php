@props([
    'action',
    'method' => 'GET',
])

<form method="{{ strtoupper($method) }}" action="{{ $action }}" {{ $attributes->class(['admin-filter-bar']) }}>
    {{ $slot }}
</form>
