<div
    class="rich-editor"
    data-rich-editor
    data-required="{{ $required ? '1' : '0' }}"
    data-editor-label="{{ $label }}"
    data-empty-message="{{ __('Add text in the default language.') }}"
    data-too-large-message="{{ __('The formatted content is too large. Reduce the text, tables, or images and try again.') }}"
    data-uploading-message="{{ __('Uploading image…') }}"
    data-upload-failed-message="{{ __('Could not upload the image.') }}"
    data-image-uploaded-message="{{ __('Image uploaded. Add a description.') }}"
    data-image-added-message="{{ __('Image added.') }}"
    data-upload-error-message="{{ __('Image upload error.') }}"
    data-unsafe-link-message="{{ __('This link address is not allowed.') }}"
    data-upload-url="{{ $uploadUrl }}"
>
    <div class="rich-editor-toolbar" role="toolbar" aria-label="{{ __('Text formatting') }}">
        <span class="editor-toolbar-group">
            <button type="button" data-editor-action="undo" title="{{ __('Undo') }}" aria-label="{{ __('Undo') }}">↶</button>
            <button type="button" data-editor-action="redo" title="{{ __('Redo') }}" aria-label="{{ __('Redo') }}">↷</button>
        </span>

        <select class="editor-select editor-block-select" data-editor-block aria-label="{{ __('Paragraph style') }}" title="{{ __('Paragraph style') }}">
            <option value="p">{{ __('Normal text') }}</option>
            <option value="h2">{{ __('Heading 2') }}</option>
            <option value="h3">{{ __('Heading 3') }}</option>
            <option value="h4">{{ __('Heading 4') }}</option>
            <option value="blockquote">{{ __('Quote') }}</option>
            <option value="pre">{{ __('Code block') }}</option>
        </select>

        <span class="editor-toolbar-group">
            <button type="button" data-editor-action="bold" title="{{ __('Bold') }}" aria-label="{{ __('Bold') }}"><strong>B</strong></button>
            <button type="button" data-editor-action="italic" title="{{ __('Italic') }}" aria-label="{{ __('Italic') }}"><em>I</em></button>
            <button type="button" data-editor-action="underline" title="{{ __('Underline') }}" aria-label="{{ __('Underline') }}"><u>U</u></button>
            <button type="button" data-editor-action="strike" title="{{ __('Strikethrough') }}" aria-label="{{ __('Strikethrough') }}"><s>S</s></button>
            <button type="button" data-editor-action="code" title="{{ __('Inline code') }}" aria-label="{{ __('Inline code') }}">&lt;/&gt;</button>
        </span>

        <span class="editor-toolbar-group">
            <button type="button" data-editor-action="bulletList" title="{{ __('Bulleted list') }}" aria-label="{{ __('Bulleted list') }}">• {{ __('List') }}</button>
            <button type="button" data-editor-action="orderedList" title="{{ __('Numbered list') }}" aria-label="{{ __('Numbered list') }}">1. {{ __('List') }}</button>
            <button type="button" data-editor-action="blockquote" title="{{ __('Quote') }}" aria-label="{{ __('Quote') }}">❝</button>
        </span>

        <span class="editor-toolbar-group">
            <button type="button" data-editor-align="left" title="{{ __('Align left') }}" aria-label="{{ __('Align left') }}">⇤</button>
            <button type="button" data-editor-align="center" title="{{ __('Align center') }}" aria-label="{{ __('Align center') }}">↔</button>
            <button type="button" data-editor-align="right" title="{{ __('Align right') }}" aria-label="{{ __('Align right') }}">⇥</button>
            <button type="button" data-editor-align="justify" title="{{ __('Justify') }}" aria-label="{{ __('Justify') }}">☰</button>
        </span>

        <details class="editor-menu">
            <summary>{{ __('Text color') }}</summary>
            <div class="editor-palette" role="group" aria-label="{{ __('Text color') }}">
                <button type="button" class="editor-swatch editor-swatch-default" data-editor-color="default" aria-label="{{ __('Default color') }}">×</button>
                @foreach (['gold', 'amber', 'yellow', 'red', 'rose', 'coral', 'orange', 'brown', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'slate', 'gray', 'zinc', 'muted'] as $color)
                    <button type="button" class="editor-swatch" data-editor-color="{{ $color }}" aria-label="{{ __(ucfirst($color)) }}"></button>
                @endforeach
            </div>
        </details>

        <details class="editor-menu">
            <summary>{{ __('Background color') }}</summary>
            <div class="editor-palette" role="group" aria-label="{{ __('Background color') }}">
                <button type="button" class="editor-swatch editor-swatch-default" data-editor-highlight="default" aria-label="{{ __('No background color') }}">×</button>
                @foreach (['yellow', 'amber', 'orange', 'rose', 'red', 'lime', 'green', 'teal', 'cyan', 'blue', 'purple', 'gray'] as $highlight)
                    <button type="button" class="editor-swatch" data-editor-highlight="{{ $highlight }}" aria-label="{{ __(ucfirst($highlight)) }}"></button>
                @endforeach
            </div>
        </details>

        <select class="editor-select editor-size-select" data-editor-size aria-label="{{ __('Text size') }}" title="{{ __('Text size') }}">
            <option value="default">{{ __('Normal size') }}</option>
            <option value="small">{{ __('Small text') }}</option>
            <option value="large">{{ __('Large text') }}</option>
        </select>

        <span class="editor-toolbar-group">
            <button type="button" data-editor-link title="{{ __('Add or edit link') }}">{{ __('Link') }}</button>
            <button type="button" data-editor-action="unlink" title="{{ __('Remove link') }}">{{ __('Unlink') }}</button>
            <button type="button" data-editor-action="horizontalRule" title="{{ __('Divider') }}">{{ __('Line') }}</button>
            <button type="button" data-editor-image title="{{ __('Insert or edit image') }}">{{ __('Image') }}</button>
        </span>

        <details class="editor-menu">
            <summary>{{ __('Table') }}</summary>
            <div class="editor-menu-list">
                <button type="button" data-editor-action="table">{{ __('Insert 3 × 3 table') }}</button>
                <div data-editor-table-tools hidden>
                    <button type="button" data-editor-action="addRow">{{ __('Add row') }}</button>
                    <button type="button" data-editor-action="deleteRow">{{ __('Delete row') }}</button>
                    <button type="button" data-editor-action="addColumn">{{ __('Add column') }}</button>
                    <button type="button" data-editor-action="deleteColumn">{{ __('Delete column') }}</button>
                    <button type="button" data-editor-action="deleteTable">{{ __('Delete table') }}</button>
                </div>
            </div>
        </details>

        <span class="editor-toolbar-group editor-toolbar-group-end">
            <button type="button" data-editor-action="clear" title="{{ __('Clear formatting') }}">{{ __('Clear') }}</button>
            <button type="button" data-editor-fullscreen aria-pressed="false" title="{{ __('Full screen') }}">{{ __('Full screen') }}</button>
        </span>
    </div>

    <div id="{{ $editorId }}" class="rich-editor-canvas" data-placeholder="{{ $placeholder }}"></div>
    <textarea id="{{ $sourceId }}" name="{{ $sourceName }}" class="rich-editor-source" maxlength="200000" hidden>{{ $body }}</textarea>
    <input type="file" data-editor-image-input accept="image/jpeg,image/png,image/webp" hidden>

    <div class="rich-editor-footer">
        <span>{{ __('Safe headings, lists, links, colors, tables and images are allowed.') }}</span>
        <span class="rich-editor-meta">
            <span><span data-editor-count>0</span> / 200000 {{ __('Character count unit') }}</span>
            <span data-editor-status aria-live="polite"></span>
        </span>
    </div>

    <dialog class="rich-editor-dialog" data-editor-link-dialog aria-labelledby="{{ $editorId }}-link-title">
        <div data-editor-link-dialog-content>
            <h3 id="{{ $editorId }}-link-title">{{ __('Add or edit link') }}</h3>
            <label for="{{ $editorId }}-link-url">{{ __('Link URL') }}</label>
            <input id="{{ $editorId }}-link-url" data-editor-link-url type="text" inputmode="url" maxlength="2048" placeholder="https://example.com">
            <small>{{ __('Use https://, mailto:, an anchor or a relative website path.') }}</small>
            <div class="rich-editor-dialog-actions">
                <button type="button" class="button button-secondary" data-editor-dialog-cancel>{{ __('Cancel') }}</button>
                <button type="button" class="button" data-editor-link-apply>{{ __('Apply') }}</button>
            </div>
        </div>
    </dialog>

    <dialog class="rich-editor-dialog" data-editor-image-dialog aria-labelledby="{{ $editorId }}-image-title">
        <div data-editor-image-dialog-content>
            <h3 id="{{ $editorId }}-image-title">{{ __('Image settings') }}</h3>
            <label for="{{ $editorId }}-image-alt">{{ __('Image description') }}</label>
            <input id="{{ $editorId }}-image-alt" data-editor-image-alt type="text" maxlength="255">
            <small>{{ __('Describe the image for visitors who cannot see it.') }}</small>

            <label for="{{ $editorId }}-image-caption">{{ __('Image caption') }}</label>
            <input id="{{ $editorId }}-image-caption" data-editor-image-caption type="text" maxlength="500">

            <div class="rich-editor-dialog-grid">
                <label>
                    <span>{{ __('Alignment') }}</span>
                    <select data-editor-image-align>
                        <option value="left">{{ __('Align left') }}</option>
                        <option value="center">{{ __('Align center') }}</option>
                        <option value="right">{{ __('Align right') }}</option>
                    </select>
                </label>
                <label>
                    <span>{{ __('Image size') }}</span>
                    <select data-editor-image-size>
                        <option value="narrow">{{ __('Narrow') }}</option>
                        <option value="medium">{{ __('Medium') }}</option>
                        <option value="wide">{{ __('Wide') }}</option>
                        <option value="full">{{ __('Full width') }}</option>
                    </select>
                </label>
            </div>

            <div class="rich-editor-dialog-actions">
                <button type="button" class="button button-secondary" data-editor-dialog-cancel>{{ __('Cancel') }}</button>
                <button type="button" class="button" data-editor-image-apply>{{ __('Apply') }}</button>
            </div>
        </div>
    </dialog>
</div>
