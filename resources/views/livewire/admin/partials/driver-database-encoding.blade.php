<div class="ud-grid">
    <label for="ud-{{ $prefix }}-charset">{{ __('drivers.charset') }}
        <input id="ud-{{ $prefix }}-charset" type="text" maxlength="64"
            wire:model="draft.config.database.charset" placeholder="utf8mb4" required>
        @error('draft.config.database.charset')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
    </label>
    <label for="ud-{{ $prefix }}-collation">{{ __('drivers.collation') }}
        <input id="ud-{{ $prefix }}-collation" type="text" maxlength="64"
            wire:model="draft.config.database.collation" placeholder="utf8mb4_unicode_ci" required>
        @error('draft.config.database.collation')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
    </label>
</div>
<p class="ud-info">{{ __('drivers.encoding_profile_note') }}</p>
