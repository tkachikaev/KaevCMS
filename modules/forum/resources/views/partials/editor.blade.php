@php
    // Keep a plain textarea for clients without JavaScript. On successful Tiptap
    // initialization the compact canvas replaces it, using the shared editor bundle.
    $oldPlain = old('body', $initialBodyText ?? '');
    $oldRich = old('body_html', $initialBodyHtml ?? '');
    $initialRich = is_string($oldRich) && $oldRich !== ''
        ? $oldRich
        : (is_string($oldPlain) && $oldPlain !== ''
            ? '<p>'.str_replace("\n", '<br>', e($oldPlain)).'</p>' : '');
    // A JavaScript-free retry must edit and submit the visible plain text, never
    // the stale HTML flashed from an earlier rich-editor submission.
    $fallbackPlain = is_string($oldPlain) ? $oldPlain : '';
    if (is_string($oldRich) && $oldRich !== '') {
        $fallbackPlain = app(\App\Services\Html\SafeHtmlSanitizer::class)
            ->plainText($oldRich, \App\Services\Html\SafeHtmlSanitizer::PROFILE_FORUM);
    }
    $editorId = $editorId ?? 'forum-editor';
@endphp
<div class="forum-editor" data-rich-editor data-required="1"
     data-editor-label="{{ __('module-forum::messages.message_text') }}"
     data-empty-message="{{ __('module-forum::messages.body_required') }}"
     data-too-large-message="{{ __('module-forum::messages.content_too_large') }}"
     data-unsafe-link-message="{{ __('module-forum::messages.unsafe_link') }}">
    <div class="rich-editor-toolbar forum-editor-toolbar" role="toolbar" aria-label="{{ __('module-forum::messages.formatting') }}">
        <button type="button" data-editor-action="bold" title="{{ __('module-forum::messages.bold') }}" aria-label="{{ __('module-forum::messages.bold') }}"><strong>B</strong></button>
        <button type="button" data-editor-action="italic" title="{{ __('module-forum::messages.italic') }}" aria-label="{{ __('module-forum::messages.italic') }}"><em>I</em></button>
        <button type="button" data-editor-action="underline" title="{{ __('module-forum::messages.underline') }}" aria-label="{{ __('module-forum::messages.underline') }}"><u>U</u></button>
        <span class="forum-editor-divider" aria-hidden="true"></span>
        <button type="button" data-editor-action="bulletList" title="{{ __('module-forum::messages.bullet_list') }}" aria-label="{{ __('module-forum::messages.bullet_list') }}">• ≡</button>
        <button type="button" data-editor-action="orderedList" title="{{ __('module-forum::messages.ordered_list') }}" aria-label="{{ __('module-forum::messages.ordered_list') }}">1. ≡</button>
        <button type="button" data-editor-action="blockquote" title="{{ __('module-forum::messages.quote') }}" aria-label="{{ __('module-forum::messages.quote') }}">❝</button>
        <select data-editor-block aria-label="{{ __('module-forum::messages.block_type') }}">
            <option value="p">{{ __('module-forum::messages.normal_text') }}</option>
            <option value="blockquote">{{ __('module-forum::messages.quote') }}</option>
            <option value="pre">{{ __('module-forum::messages.code_block') }}</option>
        </select>
        <button type="button" data-editor-link title="{{ __('module-forum::messages.insert_link') }}" aria-label="{{ __('module-forum::messages.insert_link') }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>
    </div>

    <div id="{{ $editorId }}" class="rich-editor-canvas forum-editor-canvas"
         data-placeholder="{{ __('module-forum::messages.message_placeholder') }}"></div>
    <textarea class="rich-editor-source" name="body_html" maxlength="20000" disabled hidden>{{ $initialRich }}</textarea>

    <label class="forum-compose-field forum-editor-fallback">
        <span>{{ __('module-forum::messages.message_text') }}</span>
        <textarea name="body" rows="{{ $rows ?? 6 }}" maxlength="10000" minlength="2">{{ $fallbackPlain }}</textarea>
    </label>

    <span class="forum-editor-status" data-editor-status role="status" aria-live="polite"></span>

    <dialog class="forum-editor-link-dialog" data-editor-link-dialog aria-labelledby="{{ $editorId }}-link-title">
        <h3 id="{{ $editorId }}-link-title">{{ __('module-forum::messages.insert_link') }}</h3>
        <label>
            <span>{{ __('module-forum::messages.link_url') }}</span>
            <input type="text" data-editor-link-url inputmode="url" maxlength="2048" placeholder="https://example.com">
        </label>
        <div class="forum-dialog-actions">
            <button class="forum-button" type="button" data-editor-dialog-cancel>{{ __('module-forum::messages.cancel') }}</button>
            <button class="forum-button forum-button-primary" type="button" data-editor-link-apply>{{ __('module-forum::messages.save') }}</button>
        </div>
    </dialog>
</div>
