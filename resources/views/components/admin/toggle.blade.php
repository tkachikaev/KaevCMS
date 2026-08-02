@props([
    'id',
    'name',
    'label',
    'hint' => null,
    'checked' => false,
    'disabled' => false,
    'compact' => false,
    'value' => '1',
    'hiddenValue' => '0',
    'inputAttributes' => [],
])

@php($inputAttributes = new \Illuminate\View\ComponentAttributeBag($inputAttributes))

<label {{ $attributes->class([
    'admin-toggle-row',
    'admin-toggle-row-compact' => $compact,
    'is-disabled' => $disabled,
]) }} for="{{ $id }}">
    <span class="admin-toggle-copy">
        <strong>{{ $label }}</strong>
        @if(isset($help))
            <small>{{ $help }}</small>
        @elseif(filled($hint))
            <small>{{ $hint }}</small>
        @endif
    </span>
    <span class="admin-switch-control">
        <input name="{{ $name }}" type="hidden" value="{{ $hiddenValue }}" @disabled($disabled)>
        <input id="{{ $id }}" name="{{ $name }}" type="checkbox" value="{{ $value }}" @checked($checked) @disabled($disabled) {{ $inputAttributes }}>
        <span aria-hidden="true"></span>
    </span>
</label>
