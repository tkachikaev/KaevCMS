@props([
    'for' => null,
    'name' => null,
    'label' => null,
    'hint' => null,
    'compact' => false,
])

@php
    $fieldName = $name ?: $for;
    $message = $fieldName && isset($errors) ? $errors->first($fieldName) : null;
@endphp

<div {{ $attributes->class([
    'admin-field',
    'admin-field-compact' => $compact,
    'has-error' => filled($message),
]) }}>
    @if(filled($label))
        <label class="admin-field-label" @if(filled($for)) for="{{ $for }}" @endif>{{ $label }}</label>
    @endif

    {{ $slot }}

    @if(filled($message))
        <small class="admin-field-error" role="alert">{{ $message }}</small>
    @endif

    @if(isset($help))
        <small class="admin-field-help">{{ $help }}</small>
    @elseif(filled($hint))
        <small class="admin-field-help">{{ $hint }}</small>
    @endif
</div>
