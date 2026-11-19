@php
    // Keep a plain textarea for clients without JavaScript. On successful Tiptap
    // initialization the compact canvas replaces it, using the shared editor bundle.
    $oldPlain = old('body', '');
    $oldRich = old('body_html', '');
    $initialRich = is_string($oldRich) && $oldRich !== ''
        ? $oldRich
        : (is_string($oldPlain) && $oldPlain !== ''
            ? '<p>'.str_replace("\n", '<br>', e($oldPlain)).'</p>' : '');
    $editorId = $editorId ?? 'forum-editor';
@endphp
<div class="forum-editor" data-rich-editor data-required="1"
     data-editor-label="{{ __('module-forum::messages.message_text') }}"
     data-empty-message="{{ __('module-forum::messages.body_required') }}"
     data-too-large-message="{{ __('module-forum::messages.content_too_large') }}"
     data-unsafe-link-message="{{ __('module-forum::messages.unsafe_link') }}">
    <div class="rich-editor-toolbar forum-editor-toolbar" role="toolbar" aria-label="{{ __('module-forum::messages.formatting') }}">
        <button type="button" data-editor-action="bold" title="{{ __('module-forum::messages.bold') }}" aria-label="{{ __('module-forum::messages.bold') }}"><strong>Ж</strong></button>
        <button type="button" data-editor-action="italic" title="{{ __('module-forum::messages.italic') }}" aria-label="{{ __('module-forum::messages.italic') }}"><em>К</em></button>
        <button type="button" data-editor-action="underline" title="{{ __('module-forum::messages.underline') }}" aria-label="{{ __('module-forum::messages.underline') }}"><u>Ч</u></button>
        <span class="forum-editor-divider" aria-hidden="true"></span>
        <button type="button" data-editor-action="bulletList" title="{{ __('module-forum::messages.bullet_list') }}" aria-label="{{ __('module-forum::messages.bullet_list') }}">• ≡</button>
        <button type="button" data-editor-action="orderedList" title="{{ __('module-forum::messages.ordered_list') }}" aria-label="{{ __('module-forum::messages.ordered_list') }}">1. ≡</button>
        <button type="button" data-editor-action="blockquote" title="{{ __('module-forum::messages.quote') }}" aria-label="{{ __('module-forum::messages.quote') }}">❝</button>
        <select data-editor-block aria-label="{{ __('module-forum::messages.block_type') }}">
            <option value="p">{{ __('module-forum::messages.normal_text') }}</option>
            <option value="blockquote">{{ __('module-forum::messages.quote') }}</option>
            <option value="pre">{{ __('module-forum::messages.code_block') }}</option>
        </select>
        <button type="button" data-editor-link title="{{ __('module-forum::messages.insert_link') }}" aria-label="{{ __('module-forum::messages.insert_link') }}">🔗</button>
    </div>

    <div id="{{ $editorId }}" class="rich-editor-canvas forum-editor-canvas"
         data-placeholder="{{ __('module-forum::messages.message_placeholder') }}"></div>
    <textarea class="rich-editor-source" name="body_html" maxlength="20000" hidden>{{ $initialRich }}</textarea>

    <label class="forum-compose-field forum-editor-fallback">
        <span>{{ __('module-forum::messages.message_text') }}</span>
        <textarea name="body" rows="{{ $rows ?? 6 }}" maxlength="10000" minlength="2">{{ old('body') }}</textarea>
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
