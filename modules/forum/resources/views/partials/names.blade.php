<div class="forum-form-grid">
    <label>
        <span>{{ __('module-forum::messages.name_ru') }}</span>
        <input name="name_ru" required maxlength="120" value="{{ $item?->name_ru ?? '' }}">
    </label>
    <label>
        <span>{{ __('module-forum::messages.name_en') }}</span>
        <input name="name_en" maxlength="120" value="{{ $item?->name_en ?? '' }}">
    </label>
    <label>
        <span>{{ __('module-forum::messages.description_ru') }}</span>
        <textarea name="description_ru" maxlength="500" rows="2">{{ $item?->description_ru ?? '' }}</textarea>
    </label>
    <label>
        <span>{{ __('module-forum::messages.description_en') }}</span>
        <textarea name="description_en" maxlength="500" rows="2">{{ $item?->description_en ?? '' }}</textarea>
    </label>
</div>
