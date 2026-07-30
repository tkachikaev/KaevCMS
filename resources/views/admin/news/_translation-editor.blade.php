@php
    $values = $translations[$locale] ?? ['title' => '', 'excerpt' => '', 'body' => ''];
    $title = old('translations.'.$locale.'.title', $values['title'] ?? '');
    $excerpt = old('translations.'.$locale.'.excerpt', $values['excerpt'] ?? '');
    $body = old('translations.'.$locale.'.body', $values['body'] ?? '');
    $editorBody = app(\App\Services\News\NewsHtmlSanitizer::class)->sanitize((string) $body);
    $requiredLocale = $locale === $defaultLocale;
@endphp

<div role="tabpanel" @class(['translation-panel', 'active' => $requiredLocale]) data-locale-panel="{{ $locale }}" @if(!$requiredLocale) hidden @endif>
    <div class="form-group">
        <label for="title_{{ $locale }}">{{ __('Title') }}</label>
        <input id="title_{{ $locale }}" name="translations[{{ $locale }}][title]" type="text" value="{{ $title }}" maxlength="255" @if($requiredLocale) required autofocus @endif>
        <small>{{ __('The title shown in the news list and on the publication page.') }}</small>
    </div>

    <div class="form-group">
        <label for="excerpt_{{ $locale }}">{{ __('Short description') }}</label>
        <textarea id="excerpt_{{ $locale }}" name="translations[{{ $locale }}][excerpt]" rows="4" maxlength="1000">{{ $excerpt }}</textarea>
        <small>{{ __('Shown in the news list. Leave empty to generate it from the article text.') }}</small>
    </div>

    <div class="form-group">
        <label for="body-editor-{{ $locale }}">{{ __('News text') }}</label>
        @include('admin.content._rich-editor', [
            'editorId' => 'body-editor-'.$locale,
            'sourceId' => 'body_'.$locale,
            'sourceName' => 'translations['.$locale.'][body]',
            'label' => __('News text'),
            'placeholder' => __('Start writing news…'),
            'body' => $editorBody,
            'required' => $requiredLocale,
            'uploadUrl' => route('admin.news.images.store'),
        ])
        <small>{{ __('HTML is sanitized on the server. Scripts, styles, iframes and unsafe attributes are removed.') }}</small>
    </div>
</div>
