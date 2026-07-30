@php
    $values = $translations[$locale] ?? ['title' => '', 'slug' => '', 'body' => '', 'seo_title' => '', 'seo_description' => ''];
    $title = old('translations.'.$locale.'.title', $values['title'] ?? '');
    $slug = old('translations.'.$locale.'.slug', $values['slug'] ?? '');
    $body = old('translations.'.$locale.'.body', $values['body'] ?? '');
    $seoTitle = old('translations.'.$locale.'.seo_title', $values['seo_title'] ?? '');
    $seoDescription = old('translations.'.$locale.'.seo_description', $values['seo_description'] ?? '');
    $editorBody = app(\App\Services\Pages\PageHtmlSanitizer::class)->sanitize((string) $body);
    $requiredLocale = $locale === $defaultLocale;
@endphp

<div role="tabpanel" @class(['translation-panel', 'active' => $requiredLocale]) data-locale-panel="{{ $locale }}" @if(!$requiredLocale) hidden @endif>
    <div class="form-group">
        <label for="page_title_{{ $locale }}">{{ __('Title') }}</label>
        <input id="page_title_{{ $locale }}" name="translations[{{ $locale }}][title]" type="text" value="{{ $title }}" maxlength="255" @if($requiredLocale) required autofocus @endif>
        <small>{{ __('Shown as the page heading and navigation item.') }}</small>
    </div>

    <div class="form-group">
        <label for="page_slug_{{ $locale }}">{{ __('Page address') }}</label>
        <div class="input-prefix-row">
            <span>/{{ $locale }}/pages/</span>
            <input id="page_slug_{{ $locale }}" name="translations[{{ $locale }}][slug]" type="text" value="{{ $slug }}" maxlength="160" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="server-rules">
        </div>
        <small>{{ __('Leave empty to generate it from the title. Use lowercase Latin letters, numbers and hyphens.') }}</small>
    </div>

    <div class="form-group">
        <label for="page-body-editor-{{ $locale }}">{{ __('Page text') }}</label>
        @include('admin.content._rich-editor', [
            'editorId' => 'page-body-editor-'.$locale,
            'sourceId' => 'page_body_'.$locale,
            'sourceName' => 'translations['.$locale.'][body]',
            'label' => __('Page text'),
            'placeholder' => __('Start writing page…'),
            'body' => $editorBody,
            'required' => $requiredLocale,
            'uploadUrl' => route('admin.pages.images.store'),
        ])
        <small>{{ __('HTML is sanitized on the server. Scripts, styles, iframes and unsafe attributes are removed.') }}</small>
    </div>

    <section class="seo-fields">
        <h3>{{ __('Search engines') }}</h3>
        <div class="form-group">
            <label for="seo_title_{{ $locale }}">{{ __('SEO title') }}</label>
            <input id="seo_title_{{ $locale }}" name="translations[{{ $locale }}][seo_title]" type="text" value="{{ $seoTitle }}" maxlength="255">
            <small>{{ __('Optional. The page title is used when this field is empty.') }}</small>
        </div>
        <div class="form-group">
            <label for="seo_description_{{ $locale }}">{{ __('SEO description') }}</label>
            <textarea id="seo_description_{{ $locale }}" name="translations[{{ $locale }}][seo_description]" rows="3" maxlength="500">{{ $seoDescription }}</textarea>
            <small>{{ __('Optional description for search results and link previews.') }}</small>
        </div>
    </section>
</div>
