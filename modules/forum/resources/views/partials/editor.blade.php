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
        <details class="forum-editor-menu" data-language-menu>
            <summary title="{{ __('module-forum::messages.text_color') }}" aria-label="{{ __('module-forum::messages.text_color') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 16 7-13 7 13M8 11h8"/><path d="M3 21h18" stroke-width="3" class="forum-color-bar"/></svg>
            </summary>
            <div class="forum-editor-menu-panel forum-color-grid" role="group" aria-label="{{ __('module-forum::messages.text_color') }}">
                <button type="button" class="forum-color-swatch" data-editor-color="red" data-color="red" aria-label="{{ __('module-forum::messages.text_color') }} 1"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="crimson" data-color="crimson" aria-label="{{ __('module-forum::messages.text_color') }} 2"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="wine" data-color="wine" aria-label="{{ __('module-forum::messages.text_color') }} 3"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="maroon" data-color="maroon" aria-label="{{ __('module-forum::messages.text_color') }} 4"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="rose" data-color="rose" aria-label="{{ __('module-forum::messages.text_color') }} 5"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="pink" data-color="pink" aria-label="{{ __('module-forum::messages.text_color') }} 6"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="fuchsia" data-color="fuchsia" aria-label="{{ __('module-forum::messages.text_color') }} 7"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="magenta" data-color="magenta" aria-label="{{ __('module-forum::messages.text_color') }} 8"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="plum" data-color="plum" aria-label="{{ __('module-forum::messages.text_color') }} 9"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="purple" data-color="purple" aria-label="{{ __('module-forum::messages.text_color') }} 10"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="violet" data-color="violet" aria-label="{{ __('module-forum::messages.text_color') }} 11"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="indigo" data-color="indigo" aria-label="{{ __('module-forum::messages.text_color') }} 12"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="navy" data-color="navy" aria-label="{{ __('module-forum::messages.text_color') }} 13"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="blue" data-color="blue" aria-label="{{ __('module-forum::messages.text_color') }} 14"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="azure" data-color="azure" aria-label="{{ __('module-forum::messages.text_color') }} 15"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="sky" data-color="sky" aria-label="{{ __('module-forum::messages.text_color') }} 16"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="cyan" data-color="cyan" aria-label="{{ __('module-forum::messages.text_color') }} 17"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="steel" data-color="steel" aria-label="{{ __('module-forum::messages.text_color') }} 18"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="teal" data-color="teal" aria-label="{{ __('module-forum::messages.text_color') }} 19"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="emerald" data-color="emerald" aria-label="{{ __('module-forum::messages.text_color') }} 20"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="mint" data-color="mint" aria-label="{{ __('module-forum::messages.text_color') }} 21"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="green" data-color="green" aria-label="{{ __('module-forum::messages.text_color') }} 22"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="forest" data-color="forest" aria-label="{{ __('module-forum::messages.text_color') }} 23"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="lime" data-color="lime" aria-label="{{ __('module-forum::messages.text_color') }} 24"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="olive" data-color="olive" aria-label="{{ __('module-forum::messages.text_color') }} 25"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="yellow" data-color="yellow" aria-label="{{ __('module-forum::messages.text_color') }} 26"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="gold" data-color="gold" aria-label="{{ __('module-forum::messages.text_color') }} 27"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="amber" data-color="amber" aria-label="{{ __('module-forum::messages.text_color') }} 28"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="sand" data-color="sand" aria-label="{{ __('module-forum::messages.text_color') }} 29"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="orange" data-color="orange" aria-label="{{ __('module-forum::messages.text_color') }} 30"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="tangerine" data-color="tangerine" aria-label="{{ __('module-forum::messages.text_color') }} 31"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="coral" data-color="coral" aria-label="{{ __('module-forum::messages.text_color') }} 32"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="peach" data-color="peach" aria-label="{{ __('module-forum::messages.text_color') }} 33"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="brown" data-color="brown" aria-label="{{ __('module-forum::messages.text_color') }} 34"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="slate" data-color="slate" aria-label="{{ __('module-forum::messages.text_color') }} 35"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="gray" data-color="gray" aria-label="{{ __('module-forum::messages.text_color') }} 36"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="zinc" data-color="zinc" aria-label="{{ __('module-forum::messages.text_color') }} 37"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="silver" data-color="silver" aria-label="{{ __('module-forum::messages.text_color') }} 38"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="muted" data-color="muted" aria-label="{{ __('module-forum::messages.text_color') }} 39"></button>
                <button type="button" class="forum-color-swatch" data-editor-color="ink" data-color="ink" aria-label="{{ __('module-forum::messages.text_color') }} 40"></button>
                <button type="button" class="forum-color-reset" data-editor-color="default" title="{{ __('module-forum::messages.default_color') }}" aria-label="{{ __('module-forum::messages.default_color') }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 21-4-4a2 2 0 0 1 0-3L14 3a2 2 0 0 1 3 0l4 4a2 2 0 0 1 0 3L11 21z"/><path d="M22 21H7M5 11l9 9"/></svg>
                </button>
            </div>
        </details>
        <span class="forum-editor-divider" aria-hidden="true"></span>
        <button type="button" data-editor-align="left" title="{{ __('module-forum::messages.align_left') }}" aria-label="{{ __('module-forum::messages.align_left') }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 10h10M4 14h16M4 18h10"/></svg></button>
        <button type="button" data-editor-align="center" title="{{ __('module-forum::messages.align_center') }}" aria-label="{{ __('module-forum::messages.align_center') }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 10h10M4 14h16M7 18h10"/></svg></button>
        <button type="button" data-editor-align="right" title="{{ __('module-forum::messages.align_right') }}" aria-label="{{ __('module-forum::messages.align_right') }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M10 10h10M4 14h16M10 18h10"/></svg></button>
        <span class="forum-editor-divider" aria-hidden="true"></span>
        <button type="button" data-editor-action="bulletList" title="{{ __('module-forum::messages.bullet_list') }}" aria-label="{{ __('module-forum::messages.bullet_list') }}">• ≡</button>
        <button type="button" data-editor-action="orderedList" title="{{ __('module-forum::messages.ordered_list') }}" aria-label="{{ __('module-forum::messages.ordered_list') }}">1. ≡</button>
        <button type="button" data-editor-action="blockquote" title="{{ __('module-forum::messages.quote') }}" aria-label="{{ __('module-forum::messages.quote') }}">❝</button>
        <select data-editor-block aria-label="{{ __('module-forum::messages.block_type') }}">
            <option value="p">{{ __('module-forum::messages.normal_text') }}</option>
            <option value="blockquote">{{ __('module-forum::messages.quote') }}</option>
            <option value="pre">{{ __('module-forum::messages.code_block') }}</option>
        </select>
        <details class="forum-editor-menu" data-language-menu>
            <summary title="{{ __('module-forum::messages.emoji') }}" aria-label="{{ __('module-forum::messages.emoji') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5s1.3 2 3.5 2 3.5-2 3.5-2M9 9.5h.01M15 9.5h.01"/></svg>
            </summary>
            <div class="forum-editor-menu-panel forum-emoji-grid" role="group" aria-label="{{ __('module-forum::messages.emoji') }}">
                <button type="button" data-editor-emoji="😀">😀</button>
                <button type="button" data-editor-emoji="😁">😁</button>
                <button type="button" data-editor-emoji="😂">😂</button>
                <button type="button" data-editor-emoji="🤣">🤣</button>
                <button type="button" data-editor-emoji="😊">😊</button>
                <button type="button" data-editor-emoji="😉">😉</button>
                <button type="button" data-editor-emoji="😍">😍</button>
                <button type="button" data-editor-emoji="😘">😘</button>
                <button type="button" data-editor-emoji="😎">😎</button>
                <button type="button" data-editor-emoji="🤔">🤔</button>
                <button type="button" data-editor-emoji="😏">😏</button>
                <button type="button" data-editor-emoji="😅">😅</button>
                <button type="button" data-editor-emoji="😢">😢</button>
                <button type="button" data-editor-emoji="😭">😭</button>
                <button type="button" data-editor-emoji="😡">😡</button>
                <button type="button" data-editor-emoji="🤯">🤯</button>
                <button type="button" data-editor-emoji="😱">😱</button>
                <button type="button" data-editor-emoji="🥳">🥳</button>
                <button type="button" data-editor-emoji="😴">😴</button>
                <button type="button" data-editor-emoji="🙄">🙄</button>
                <button type="button" data-editor-emoji="👍">👍</button>
                <button type="button" data-editor-emoji="👎">👎</button>
                <button type="button" data-editor-emoji="👏">👏</button>
                <button type="button" data-editor-emoji="🙌">🙌</button>
                <button type="button" data-editor-emoji="🙏">🙏</button>
                <button type="button" data-editor-emoji="💪">💪</button>
                <button type="button" data-editor-emoji="🤝">🤝</button>
                <button type="button" data-editor-emoji="👋">👋</button>
                <button type="button" data-editor-emoji="❤️">❤️</button>
                <button type="button" data-editor-emoji="💔">💔</button>
                <button type="button" data-editor-emoji="🔥">🔥</button>
                <button type="button" data-editor-emoji="⭐">⭐</button>
                <button type="button" data-editor-emoji="✨">✨</button>
                <button type="button" data-editor-emoji="🎉">🎉</button>
                <button type="button" data-editor-emoji="🎁">🎁</button>
                <button type="button" data-editor-emoji="🏆">🏆</button>
                <button type="button" data-editor-emoji="⚔️">⚔️</button>
                <button type="button" data-editor-emoji="🛡️">🛡️</button>
                <button type="button" data-editor-emoji="🏹">🏹</button>
                <button type="button" data-editor-emoji="🧙">🧙</button>
                <button type="button" data-editor-emoji="🐉">🐉</button>
                <button type="button" data-editor-emoji="💰">💰</button>
                <button type="button" data-editor-emoji="💎">💎</button>
                <button type="button" data-editor-emoji="🍀">🍀</button>
                <button type="button" data-editor-emoji="☕">☕</button>
                <button type="button" data-editor-emoji="✅">✅</button>
                <button type="button" data-editor-emoji="❌">❌</button>
                <button type="button" data-editor-emoji="⚠️">⚠️</button>
            </div>
        </details>
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
        <label>
            <span>{{ __('module-forum::messages.link_text') }}</span>
            <input type="text" data-editor-link-text maxlength="200" placeholder="{{ __('module-forum::messages.link_text_placeholder') }}">
            <small>{{ __('module-forum::messages.link_text_hint') }}</small>
        </label>
        <div class="forum-dialog-actions">
            <button class="forum-button" type="button" data-editor-dialog-cancel>{{ __('module-forum::messages.cancel') }}</button>
            <button class="forum-button forum-button-primary" type="button" data-editor-link-apply>{{ __('module-forum::messages.save') }}</button>
        </div>
    </dialog>
</div>
