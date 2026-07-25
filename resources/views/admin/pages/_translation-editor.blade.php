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
        <div
            class="rich-editor"
            data-rich-editor
            data-required="{{ $requiredLocale ? '1' : '0' }}"
            data-empty-message="{{ __('Add text in the default language.') }}"
            data-link-prompt="{{ __('Link URL:') }}"
            data-uploading-message="{{ __('Uploading image…') }}"
            data-upload-failed-message="{{ __('Could not upload the image.') }}"
            data-image-alt-prompt="{{ __('Short image description:') }}"
            data-image-added-message="{{ __('Image added.') }}"
            data-upload-error-message="{{ __('Image upload error.') }}"
            data-upload-url="{{ route('admin.pages.images.store') }}"
        >
            <div class="rich-editor-toolbar" role="toolbar" aria-label="{{ __('Text formatting') }}">
                <select class="editor-select" data-editor-block aria-label="{{ __('Paragraph style') }}" title="{{ __('Paragraph style') }}">
                    <option value="p">{{ __('Normal text') }}</option>
                    <option value="h2">{{ __('Heading 2') }}</option>
                    <option value="h3">{{ __('Heading 3') }}</option>
                    <option value="h4">{{ __('Heading 4') }}</option>
                    <option value="blockquote">{{ __('Quote') }}</option>
                    <option value="pre">{{ __('Code') }}</option>
                </select>

                <span class="editor-toolbar-group">
                    <button type="button" data-editor-command="bold" title="{{ __('Bold') }}" aria-label="{{ __('Bold') }}"><strong>B</strong></button>
                    <button type="button" data-editor-command="italic" title="{{ __('Italic') }}" aria-label="{{ __('Italic') }}"><em>I</em></button>
                    <button type="button" data-editor-command="underline" title="{{ __('Underline') }}" aria-label="{{ __('Underline') }}"><u>U</u></button>
                    <button type="button" data-editor-command="strikeThrough" title="{{ __('Strikethrough') }}" aria-label="{{ __('Strikethrough') }}"><s>S</s></button>
                </span>

                <span class="editor-toolbar-group">
                    <button type="button" data-editor-command="insertUnorderedList" title="{{ __('Bulleted list') }}" aria-label="{{ __('Bulleted list') }}">• {{ __('List') }}</button>
                    <button type="button" data-editor-command="insertOrderedList" title="{{ __('Numbered list') }}" aria-label="{{ __('Numbered list') }}">1. {{ __('List') }}</button>
                </span>

                <span class="editor-toolbar-group">
                    <button type="button" data-editor-align="left" title="{{ __('Align left') }}" aria-label="{{ __('Align left') }}">⇤</button>
                    <button type="button" data-editor-align="center" title="{{ __('Align center') }}" aria-label="{{ __('Align center') }}">↔</button>
                    <button type="button" data-editor-align="right" title="{{ __('Align right') }}" aria-label="{{ __('Align right') }}">⇥</button>
                </span>

                <select class="editor-select editor-color-select" data-editor-color aria-label="{{ __('Text color') }}" title="{{ __('Text color') }}">
                    <option value="default">{{ __('Text color') }}</option>
                    <option value="gold">{{ __('Gold') }}</option>
                    <option value="red">{{ __('Red') }}</option>
                    <option value="green">{{ __('Green') }}</option>
                    <option value="blue">{{ __('Blue') }}</option>
                    <option value="muted">{{ __('Gray') }}</option>
                </select>

                <span class="editor-toolbar-group">
                    <button type="button" data-editor-link title="{{ __('Add link') }}">{{ __('Link') }}</button>
                    <button type="button" data-editor-command="unlink" title="{{ __('Remove link') }}">{{ __('Unlink') }}</button>
                    <button type="button" data-editor-command="insertHorizontalRule" title="{{ __('Divider') }}">{{ __('Line') }}</button>
                    <button type="button" data-editor-image title="{{ __('Insert image') }}">{{ __('Image') }}</button>
                    <button type="button" data-editor-command="removeFormat" title="{{ __('Clear formatting') }}">{{ __('Clear') }}</button>
                </span>
            </div>

            <div id="page-body-editor-{{ $locale }}" class="rich-editor-canvas" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="{{ __('Start writing page…') }}">{!! $editorBody !!}</div>
            <textarea id="page_body_{{ $locale }}" name="translations[{{ $locale }}][body]" class="rich-editor-source" maxlength="200000" hidden>{{ $body }}</textarea>
            <input type="file" data-editor-image-input accept="image/jpeg,image/png,image/webp" hidden>

            <div class="rich-editor-footer">
                <span>{{ __('Safe headings, lists, links, colors and images are allowed.') }}</span>
                <span data-editor-status aria-live="polite"></span>
            </div>
        </div>
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
