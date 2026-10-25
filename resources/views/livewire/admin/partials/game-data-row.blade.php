<div class="ud-mapping-row">
    <div class="ud-mapping-purpose"><strong>{{ $purpose }}</strong></div>
    <span class="ud-arrow" aria-hidden="true">→</span>
    <label>{{ __('drivers.table') }}<input type="text" wire:model.blur="{{ $tablePath }}" maxlength="64"></label>
    <span class="ud-arrow" aria-hidden="true">→</span>
    <label>{{ __('drivers.column') }}<input type="text" wire:model="{{ $columnPath }}" maxlength="64"></label>
</div>
