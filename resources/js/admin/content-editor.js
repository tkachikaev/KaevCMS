import { Editor, Mark, Node, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import CharacterCount from '@tiptap/extension-character-count';
import Placeholder from '@tiptap/extension-placeholder';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';

const MAX_CONTENT_LENGTH = 200000;
const contentByteLength = (value) => new TextEncoder().encode(value).length;
const SAFE_COLORS = ['gold', 'amber', 'yellow', 'red', 'rose', 'coral', 'orange', 'brown', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'slate', 'gray', 'zinc', 'muted'];
const SAFE_HIGHLIGHTS = ['yellow', 'amber', 'orange', 'rose', 'red', 'lime', 'green', 'teal', 'cyan', 'blue', 'purple', 'gray'];
const SAFE_TEXT_SIZES = ['small', 'large'];
const SAFE_IMAGE_ALIGNMENTS = ['left', 'center', 'right'];
const SAFE_IMAGE_SIZES = ['narrow', 'medium', 'wide', 'full'];

const createTokenMark = (name, attribute, values) => Mark.create({
    name,

    parseHTML() {
        return [{
            tag: `span[${attribute}]`,
            getAttrs: (element) => {
                const value = element.getAttribute(attribute)?.toLowerCase() ?? '';

                return values.includes(value) ? { value } : false;
            },
        }];
    },

    renderHTML({ HTMLAttributes }) {
        const value = String(HTMLAttributes.value ?? '').toLowerCase();

        return values.includes(value)
            ? ['span', { [attribute]: value }, 0]
            : ['span', {}, 0];
    },

    addAttributes() {
        return {
            value: {
                default: null,
            },
        };
    },
});

const SafeColor = createTokenMark('safeColor', 'data-color', SAFE_COLORS);
const SafeHighlight = createTokenMark('safeHighlight', 'data-highlight', SAFE_HIGHLIGHTS);
const SafeTextSize = createTokenMark('safeTextSize', 'data-text-size', SAFE_TEXT_SIZES);

const SafeTable = Table.extend({
    renderHTML() {
        return ['table', {}, ['tbody', 0]];
    },
});

const SafeTableCell = TableCell.extend({
    renderHTML() {
        return ['td', {}, 0];
    },
});

const SafeTableHeader = TableHeader.extend({
    renderHTML() {
        return ['th', {}, 0];
    },
});

const SafeTextAlign = TextAlign.extend({
    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                textAlign: {
                    default: this.options.defaultAlignment,
                    parseHTML: (element) => {
                        const value = (
                            element.getAttribute('data-align')
                            || element.style.textAlign
                            || this.options.defaultAlignment
                        ).toLowerCase();

                        return this.options.alignments.includes(value)
                            ? value
                            : this.options.defaultAlignment;
                    },
                    renderHTML: (attributes) => {
                        const value = String(attributes.textAlign ?? '').toLowerCase();

                        return value !== '' && value !== this.options.defaultAlignment && this.options.alignments.includes(value)
                            ? { 'data-align': value }
                            : {};
                    },
                },
            },
        }];
    },
});

const FigureImage = Node.create({
    name: 'figureImage',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            src: { default: null },
            alt: { default: '' },
            caption: { default: '' },
            align: { default: 'left' },
            size: { default: 'full' },
        };
    },

    parseHTML() {
        return [{
            tag: 'figure',
            getAttrs: (element) => {
                const image = element.querySelector('img');
                if (!image?.getAttribute('src')) {
                    return false;
                }

                const align = element.getAttribute('data-align')?.toLowerCase() ?? 'left';
                const size = element.getAttribute('data-size')?.toLowerCase() ?? 'full';

                return {
                    src: image.getAttribute('src'),
                    alt: image.getAttribute('alt') ?? '',
                    caption: element.querySelector('figcaption')?.textContent?.trim() ?? '',
                    align: SAFE_IMAGE_ALIGNMENTS.includes(align) ? align : 'left',
                    size: SAFE_IMAGE_SIZES.includes(size) ? size : 'full',
                };
            },
        }];
    },

    renderHTML({ HTMLAttributes }) {
        const align = SAFE_IMAGE_ALIGNMENTS.includes(HTMLAttributes.align) ? HTMLAttributes.align : 'left';
        const size = SAFE_IMAGE_SIZES.includes(HTMLAttributes.size) ? HTMLAttributes.size : 'full';
        const figureAttributes = {};

        if (align !== 'left') {
            figureAttributes['data-align'] = align;
        }

        if (size !== 'full') {
            figureAttributes['data-size'] = size;
        }

        return [
            'figure',
            figureAttributes,
            ['img', mergeAttributes({
                src: HTMLAttributes.src,
                alt: HTMLAttributes.alt ?? '',
                loading: 'lazy',
                decoding: 'async',
            })],
            ['figcaption', {}, HTMLAttributes.caption ?? ''],
        ];
    },

    addCommands() {
        return {
            insertFigure: (attributes) => ({ commands }) => commands.insertContent({
                type: this.name,
                attrs: attributes,
            }),
        };
    },
});

const initializeCoverPreview = (signal) => {
    const wrapper = document.querySelector('[data-cover-upload]');
    if (!wrapper) {
        return;
    }

    const input = wrapper.querySelector('[data-cover-input]');
    const preview = wrapper.querySelector('[data-cover-preview]');
    const remove = wrapper.querySelector('[data-cover-remove]');

    if (!input || !preview) {
        return;
    }

    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file) {
            return;
        }

        if (remove) {
            remove.checked = false;
        }

        const url = URL.createObjectURL(file);
        const image = document.createElement('img');
        image.src = url;
        image.alt = wrapper.dataset.previewAlt ?? 'Selected image preview';
        image.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
        preview.replaceChildren(image);
        preview.classList.add('has-image');
        preview.classList.remove('marked-for-removal');
    }, { signal });

    remove?.addEventListener('change', () => {
        preview.classList.toggle('marked-for-removal', remove.checked);
    }, { signal });
};

const normalizeLinkUrl = (rawValue) => {
    let value = rawValue.trim();
    if (value === '') {
        return '';
    }

    if (/^(?:www\.)[^\s/]+\.[^\s]+$/i.test(value) || /^[^\s/@]+\.[^\s]+$/i.test(value)) {
        value = `https://${value}`;
    }

    if (/^(?:javascript|vbscript|data|file):/i.test(value) || value.startsWith('//') || value.includes('\\')) {
        return null;
    }

    return value;
};

const initializeRichEditor = (root, signal) => {
    const canvas = root.querySelector('.rich-editor-canvas');
    const source = root.querySelector('.rich-editor-source');
    const form = root.closest('form');
    const toolbar = root.querySelector('.rich-editor-toolbar');
    const blockSelect = root.querySelector('[data-editor-block]');
    const sizeSelect = root.querySelector('[data-editor-size]');
    const count = root.querySelector('[data-editor-count]');
    const status = root.querySelector('[data-editor-status]');
    const imageInput = root.querySelector('[data-editor-image-input]');
    const linkDialog = root.querySelector('[data-editor-link-dialog]');
    const linkApply = root.querySelector('[data-editor-link-apply]');
    const linkUrl = root.querySelector('[data-editor-link-url]');
    const imageDialog = root.querySelector('[data-editor-image-dialog]');
    const imageApply = root.querySelector('[data-editor-image-apply]');
    const imageAlt = root.querySelector('[data-editor-image-alt]');
    const imageCaption = root.querySelector('[data-editor-image-caption]');
    const imageAlign = root.querySelector('[data-editor-image-align]');
    const imageSize = root.querySelector('[data-editor-image-size]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let pendingImage = null;
    let statusTimer = null;

    if (!canvas || !source || !form || !toolbar) {
        return null;
    }

    const setStatus = (message, type = '', clearAfter = 0) => {
        if (!status) {
            return;
        }

        window.clearTimeout(statusTimer);
        status.textContent = message;
        status.dataset.type = type;

        if (clearAfter > 0) {
            statusTimer = window.setTimeout(() => {
                status.textContent = '';
                status.dataset.type = '';
            }, clearAfter);
        }
    };

    const editor = new Editor({
        element: canvas,
        content: source.value,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3, 4] },
                link: {
                    autolink: true,
                    linkOnPaste: true,
                    openOnClick: false,
                    HTMLAttributes: {
                        rel: 'noopener noreferrer nofollow',
                    },
                },
            }),
            Placeholder.configure({
                placeholder: canvas.dataset.placeholder ?? '',
            }),
            CharacterCount.configure({
                limit: MAX_CONTENT_LENGTH,
            }),
            SafeTextAlign.configure({
                types: ['heading', 'paragraph', 'blockquote'],
                alignments: ['left', 'center', 'right', 'justify'],
                defaultAlignment: 'left',
            }),
            SafeColor,
            SafeHighlight,
            SafeTextSize,
            FigureImage,
            SafeTable.configure({
                resizable: false,
                renderWrapper: false,
            }),
            TableRow,
            SafeTableHeader,
            SafeTableCell,
        ],
        editorProps: {
            attributes: {
                role: 'textbox',
                'aria-label': root.dataset.editorLabel ?? '',
                'aria-multiline': 'true',
                spellcheck: 'true',
            },
        },
    });

    root.dataset.editorReady = '1';

    const syncSource = () => {
        const html = editor.getHTML();
        source.value = html === '<p></p>' ? '' : html;
        source.dispatchEvent(new Event('input', { bubbles: true }));
    };

    const hasRequiredContent = () => {
        if (editor.getText().trim() !== '') {
            return true;
        }

        let found = false;
        editor.state.doc.descendants((node) => {
            if (node.type.name === 'figureImage' || node.type.name === 'horizontalRule' || node.type.name === 'table') {
                found = true;

                return false;
            }

            return !found;
        });

        return found;
    };

    const currentBlock = () => {
        if (editor.isActive('heading', { level: 2 })) return 'h2';
        if (editor.isActive('heading', { level: 3 })) return 'h3';
        if (editor.isActive('heading', { level: 4 })) return 'h4';
        if (editor.isActive('blockquote')) return 'blockquote';
        if (editor.isActive('codeBlock')) return 'pre';

        return 'p';
    };

    const updateToolbar = () => {
        if (blockSelect) {
            blockSelect.value = currentBlock();
        }

        const activeStates = {
            bold: editor.isActive('bold'),
            italic: editor.isActive('italic'),
            underline: editor.isActive('underline'),
            strike: editor.isActive('strike'),
            code: editor.isActive('code'),
            bulletList: editor.isActive('bulletList'),
            orderedList: editor.isActive('orderedList'),
            blockquote: editor.isActive('blockquote'),
        };

        root.querySelectorAll('[data-editor-action]').forEach((button) => {
            const active = activeStates[button.dataset.editorAction] === true;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        root.querySelectorAll('[data-editor-align]').forEach((button) => {
            const active = editor.isActive({ textAlign: button.dataset.editorAlign });
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        if (sizeSelect) {
            sizeSelect.value = SAFE_TEXT_SIZES.find((value) => editor.isActive('safeTextSize', { value })) ?? 'default';
        }

        if (count) {
            count.textContent = String(editor.storage.characterCount.characters());
        }

        root.querySelector('[data-editor-table-tools]')?.toggleAttribute('hidden', !editor.isActive('table'));
    };

    const runAction = (action) => {
        const chain = editor.chain().focus();
        const commands = {
            undo: () => chain.undo().run(),
            redo: () => chain.redo().run(),
            bold: () => chain.toggleBold().run(),
            italic: () => chain.toggleItalic().run(),
            underline: () => chain.toggleUnderline().run(),
            strike: () => chain.toggleStrike().run(),
            code: () => chain.toggleCode().run(),
            bulletList: () => chain.toggleBulletList().run(),
            orderedList: () => chain.toggleOrderedList().run(),
            blockquote: () => chain.toggleBlockquote().run(),
            horizontalRule: () => chain.setHorizontalRule().run(),
            unlink: () => chain.unsetLink().run(),
            clear: () => chain.unsetAllMarks().clearNodes().run(),
            table: () => chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
            addRow: () => chain.addRowAfter().run(),
            deleteRow: () => chain.deleteRow().run(),
            addColumn: () => chain.addColumnAfter().run(),
            deleteColumn: () => chain.deleteColumn().run(),
            deleteTable: () => chain.deleteTable().run(),
        };

        commands[action]?.();
    };

    toolbar.addEventListener('mousedown', (event) => {
        if (event.target.closest('button')) {
            event.preventDefault();
        }
    }, { signal });

    toolbar.addEventListener('click', (event) => {
        const actionButton = event.target.closest('[data-editor-action]');
        if (actionButton) {
            runAction(actionButton.dataset.editorAction);
            return;
        }

        const alignButton = event.target.closest('[data-editor-align]');
        if (alignButton) {
            editor.chain().focus().setTextAlign(alignButton.dataset.editorAlign).run();
            return;
        }

        const colorButton = event.target.closest('[data-editor-color]');
        if (colorButton) {
            const value = colorButton.dataset.editorColor;
            const chain = editor.chain().focus();
            value === 'default'
                ? chain.unsetMark('safeColor').run()
                : chain.setMark('safeColor', { value }).run();
            colorButton.closest('details')?.removeAttribute('open');
            return;
        }

        const highlightButton = event.target.closest('[data-editor-highlight]');
        if (highlightButton) {
            const value = highlightButton.dataset.editorHighlight;
            const chain = editor.chain().focus();
            value === 'default'
                ? chain.unsetMark('safeHighlight').run()
                : chain.setMark('safeHighlight', { value }).run();
            highlightButton.closest('details')?.removeAttribute('open');
        }
    }, { signal });

    blockSelect?.addEventListener('change', () => {
        const value = blockSelect.value;
        const chain = editor.chain().focus();

        if (value === 'h2' || value === 'h3' || value === 'h4') {
            chain.setHeading({ level: Number(value.slice(1)) }).run();
        } else if (value === 'blockquote') {
            chain.setBlockquote().run();
        } else if (value === 'pre') {
            chain.setCodeBlock().run();
        } else {
            chain.setParagraph().run();
        }
    }, { signal });

    sizeSelect?.addEventListener('change', () => {
        const chain = editor.chain().focus();
        SAFE_TEXT_SIZES.includes(sizeSelect.value)
            ? chain.setMark('safeTextSize', { value: sizeSelect.value }).run()
            : chain.unsetMark('safeTextSize').run();
    }, { signal });

    root.querySelector('[data-editor-link]')?.addEventListener('click', () => {
        if (!linkDialog || !linkUrl) {
            return;
        }

        linkUrl.value = editor.getAttributes('link').href ?? '';
        linkDialog.showModal();
        linkUrl.focus();
        linkUrl.select();
    }, { signal });

    linkApply?.addEventListener('click', (event) => {
        event.preventDefault();
        const url = normalizeLinkUrl(linkUrl?.value ?? '');

        if (url === null) {
            setStatus(root.dataset.unsafeLinkMessage ?? 'Unsafe link address.', 'error');
            linkUrl?.focus();
            return;
        }

        if (url === '') {
            editor.chain().focus().unsetLink().run();
        } else {
            editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
        }

        linkDialog?.close();
    }, { signal });

    root.querySelectorAll('[data-editor-dialog-cancel]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close(), { signal });
    });

    const openImageDialog = (attributes, mode) => {
        if (!imageDialog || !imageAlt || !imageCaption || !imageAlign || !imageSize) {
            return;
        }

        pendingImage = { mode, attributes };
        imageAlt.value = attributes.alt ?? '';
        imageCaption.value = attributes.caption ?? '';
        imageAlign.value = SAFE_IMAGE_ALIGNMENTS.includes(attributes.align) ? attributes.align : 'left';
        imageSize.value = SAFE_IMAGE_SIZES.includes(attributes.size) ? attributes.size : 'full';
        imageDialog.showModal();
        imageAlt.focus();
        imageAlt.select();
    };

    const uploadImage = async (file) => {
        if (!file || !root.dataset.uploadUrl) {
            return;
        }

        setStatus(root.dataset.uploadingMessage ?? 'Uploading image…');
        const data = new FormData();
        data.append('image', file);

        try {
            const response = await fetch(root.dataset.uploadUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: data,
                credentials: 'same-origin',
                signal,
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok || !payload.url) {
                const errors = Object.values(payload.errors ?? {}).flat();
                throw new Error(errors[0] ?? payload.message ?? root.dataset.uploadFailedMessage ?? 'Could not upload the image.');
            }

            openImageDialog({
                src: payload.url,
                alt: file.name.replace(/\.[^.]+$/, ''),
                caption: '',
                align: 'left',
                size: 'full',
            }, 'insert');
            setStatus(root.dataset.imageUploadedMessage ?? 'Image uploaded. Add a description.', 'success');
        } catch (error) {
            if (error?.name !== 'AbortError') {
                setStatus(
                    error instanceof Error ? error.message : (root.dataset.uploadErrorMessage ?? 'Image upload error.'),
                    'error',
                );
            }
        } finally {
            if (imageInput) {
                imageInput.value = '';
            }
        }
    };

    root.querySelector('[data-editor-image]')?.addEventListener('click', () => {
        if (editor.isActive('figureImage')) {
            openImageDialog(editor.getAttributes('figureImage'), 'update');
        } else {
            imageInput?.click();
        }
    }, { signal });

    imageInput?.addEventListener('change', () => uploadImage(imageInput.files?.[0]), { signal });

    imageApply?.addEventListener('click', (event) => {
        event.preventDefault();
        if (!pendingImage) {
            imageDialog?.close();
            return;
        }

        const attributes = {
            ...pendingImage.attributes,
            alt: imageAlt?.value.trim() ?? '',
            caption: imageCaption?.value.trim() ?? '',
            align: imageAlign?.value ?? 'left',
            size: imageSize?.value ?? 'full',
        };

        if (pendingImage.mode === 'update') {
            editor.chain().focus().updateAttributes('figureImage', attributes).run();
        } else {
            editor.chain().focus().insertFigure(attributes).run();
        }

        pendingImage = null;
        imageDialog?.close();
        setStatus(root.dataset.imageAddedMessage ?? 'Image added.', 'success', 4000);
    }, { signal });

    canvas.addEventListener('dragover', (event) => {
        if ([...(event.dataTransfer?.items ?? [])].some((item) => item.kind === 'file')) {
            event.preventDefault();
        }
    }, { signal });

    canvas.addEventListener('drop', (event) => {
        const file = [...(event.dataTransfer?.files ?? [])].find((item) => item.type.startsWith('image/'));
        if (!file) {
            return;
        }

        event.preventDefault();
        uploadImage(file);
    }, { signal });

    const fullscreenButton = root.querySelector('[data-editor-fullscreen]');
    fullscreenButton?.addEventListener('click', () => {
        const active = root.classList.toggle('is-fullscreen');
        document.body.classList.toggle('rich-editor-has-fullscreen', active);
        fullscreenButton.setAttribute('aria-pressed', active ? 'true' : 'false');
        editor.commands.focus();
    }, { signal });

    editor.on('update', () => {
        syncSource();
        updateToolbar();
    });
    editor.on('selectionUpdate', updateToolbar);
    editor.on('transaction', updateToolbar);

    form.addEventListener('submit', (event) => {
        syncSource();

        const isPreview = event.submitter?.matches('[data-news-preview], [data-content-preview]') ?? false;
        const methodOverride = form.querySelector('input[name="_method"]');
        if (isPreview && methodOverride) {
            methodOverride.disabled = true;
            window.setTimeout(() => {
                methodOverride.disabled = false;
            }, 0);
        }

        if (contentByteLength(source.value) > MAX_CONTENT_LENGTH) {
            event.preventDefault();
            setStatus(root.dataset.tooLargeMessage ?? 'The formatted content is too large.', 'error');
            editor.commands.focus();

            return;
        }

        if (root.dataset.required === '1' && !hasRequiredContent()) {
            event.preventDefault();
            setStatus(root.dataset.emptyMessage ?? 'Add text in the default language.', 'error');
            editor.commands.focus();
        }
    }, { signal });

    syncSource();
    updateToolbar();

    return editor;
};

const initialize = () => {
    const abortController = new AbortController();
    const editors = [];

    initializeCoverPreview(abortController.signal);
    document.querySelectorAll('[data-rich-editor]').forEach((root) => {
        const editor = initializeRichEditor(root, abortController.signal);
        if (editor) {
            editors.push(editor);
        }
    });

    return () => {
        abortController.abort();
        editors.forEach((editor) => editor.destroy());
        document.body.classList.remove('rich-editor-has-fullscreen');
    };
};

if (window.KaevCMSAdmin?.registerPage) {
    window.KaevCMSAdmin.registerPage('news-editor', initialize);
} else {
    initialize();
}
