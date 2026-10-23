{{-- "Delete" at the foot of an image card: it marks the current image for removal on save
     (the preview fades and the button offers to keep it), see settings.js. --}}
<input type="hidden" name="{{ $name }}" value="{{ old($name) ? '1' : '0' }}" data-settings-remove>
<button
    class="button button-secondary settings-remove-button"
    type="button"
    data-settings-remove-toggle
    data-label-remove="{{ __('Delete') }}"
    data-label-keep="{{ __('Do not delete') }}"
    aria-pressed="false"
>{{ __('Delete') }}</button>
