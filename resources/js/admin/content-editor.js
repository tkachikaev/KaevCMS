import { Editor, Mark, Node, mergeAttributes } from '@tiptap/core';
import { Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import CharacterCount from '@tiptap/extension-character-count';
import Placeholder from '@tiptap/extension-placeholder';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';

const MAX_CONTENT_LENGTH = 200000;
const contentByteLength = (value) => new TextEncoder().encode(value).length;
const SAFE_COLORS = ['gold', 'amber', 'yellow', 'red', 'rose', 'coral', 'orange', 'brown', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'slate', 'gray', 'zinc', 'muted',
    // Extra forum palette tones.
    'crimson', 'wine', 'maroon', 'magenta', 'plum', 'navy', 'azure', 'steel', 'mint', 'forest', 'olive', 'sand', 'tangerine', 'peach', 'silver', 'ink'];
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

const ContentCards = Node.create({
    name: 'contentCards', group: 'block', content: 'contentCard+', defining: true,
    parseHTML: () => [{ tag: 'div[data-content-block="cards"]' }],
    renderHTML: () => ['div', { 'data-content-block': 'cards' }, 0],
});
const ContentCard = Node.create({
    name: 'contentCard', content: 'block+', defining: true, isolating: true,
    parseHTML: () => [{ tag: 'div[data-content-block="card"]' }],
    renderHTML: () => ['div', { 'data-content-block': 'card' }, 0],
});
const ContentButtons = Extension.create({
    name: 'contentButtons',
    addGlobalAttributes() {
        return [{ types: ['link'], attributes: { buttonStyle: {
            default: null,
            parseHTML: element => ['primary', 'secondary'].includes(element.getAttribute('data-content-button'))
                ? element.getAttribute('data-content-button') : null,
            renderHTML: attributes => ['primary', 'secondary'].includes(attributes.buttonStyle)
                ? { 'data-content-button': attributes.buttonStyle } : {},
        } } }];
    },
});

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

const LINK_IMAGE_WIDTHS = Array.from({ length: 19 }, (_, index) => String(10 + index * 5));
const normalizeImageWidth = (value) => (LINK_IMAGE_WIDTHS.includes(String(value)) ? String(value) : '100');

const InlineLinkImage = Node.create({
    name: 'inlineLinkImage',
    group: 'inline',
    inline: true,
    atom: true,
    draggable: true,

    addAttributes() {
        return { src: { default: null }, alt: { default: '' } };
    },

    parseHTML() {
        return [{
            tag: 'img[data-inline-image="1"]',
            getAttrs: (image) => {
                const src = image.getAttribute('src') ?? '';
                return /^https:\/\//i.test(src) ? { src, alt: image.getAttribute('alt') ?? '' } : false;
            },
        }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['img', {
            ...HTMLAttributes,
            'data-inline-image': '1',
            referrerpolicy: 'no-referrer',
        }];
    },
});

// Block images from third-party HTTPS hosts can be resized with a corner handle.
const LinkImage = Node.create({
    name: 'linkImage',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            src: { default: null },
            alt: { default: '' },
            align: { default: 'left' },
            width: { default: '100' },
        };
    },

    parseHTML() {
        return [{
            tag: 'figure',
            priority: 60,
            getAttrs: (element) => {
                const image = element.querySelector('img');
                const src = image?.getAttribute('src') ?? '';
                if (!/^https:\/\//i.test(src)) {
                    return false;
                }
                const align = element.getAttribute('data-align')?.toLowerCase() ?? 'left';

                return {
                    src,
                    alt: image.getAttribute('alt') ?? '',
                    align: SAFE_IMAGE_ALIGNMENTS.includes(align) ? align : 'left',
                    width: normalizeImageWidth(element.getAttribute('data-width') ?? '100'),
                };
            },
        }];
    },

    renderHTML({ HTMLAttributes }) {
        const figure = {};
        const align = SAFE_IMAGE_ALIGNMENTS.includes(HTMLAttributes.align) ? HTMLAttributes.align : 'left';
        const width = normalizeImageWidth(HTMLAttributes.width);
        if (align !== 'left') figure['data-align'] = align;
        if (width !== '100') figure['data-width'] = width;

        return ['figure', figure, ['img', {
            src: HTMLAttributes.src,
            alt: HTMLAttributes.alt ?? '',
            loading: 'lazy',
            referrerpolicy: 'no-referrer',
        }]];
    },

    addNodeView() {
        return ({ node, getPos, editor }) => {
            const figure = document.createElement('figure');
            const frame = document.createElement('span');
            const image = document.createElement('img');
            const handle = document.createElement('span');
            figure.className = 'link-image-view';
            // The caret must never enter an atom node view: it is selected as a whole.
            figure.contentEditable = 'false';
            frame.className = 'link-image-frame';
            image.referrerPolicy = 'no-referrer';
            image.draggable = false;
            handle.className = 'link-image-handle';
            handle.setAttribute('aria-hidden', 'true');
            frame.append(image, handle);
            figure.append(frame);

            const paint = (current) => {
                image.src = current.attrs.src ?? '';
                image.alt = current.attrs.alt ?? '';
                figure.dataset.align = current.attrs.align ?? 'left';
                figure.dataset.width = normalizeImageWidth(current.attrs.width);
                figure.style.width = '';
            };
            paint(node);

            handle.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const column = figure.parentElement?.getBoundingClientRect().width || 1;
                const startX = event.clientX;
                const startWidth = image.getBoundingClientRect().width;
                const rtl = getComputedStyle(figure).direction === 'rtl';
                let width = figure.dataset.width;
                try {
                    handle.setPointerCapture(event.pointerId);
                } catch {
                    // Synthetic or already released pointers cannot be captured.
                }

                const move = (moveEvent) => {
                    const delta = (moveEvent.clientX - startX) * (rtl ? -1 : 1);
                    const percent = Math.round(((startWidth + delta) / column) * 100 / 5) * 5;
                    width = String(Math.min(100, Math.max(10, percent)));
                    figure.style.width = `${width}%`;
                };
                const stop = () => {
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', stop);
                    handle.removeEventListener('pointercancel', stop);
                    const position = typeof getPos === 'function' ? getPos() : null;
                    if (typeof position === 'number') {
                        editor.view.dispatch(editor.state.tr.setNodeMarkup(position, undefined, { ...node.attrs, width }));
                    }
                };
                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', stop);
                handle.addEventListener('pointercancel', stop);
            });

            return {
                dom: figure,
                update: (updated) => {
                    if (updated.type.name !== 'linkImage') {
                        return false;
                    }
                    node = updated;
                    paint(updated);

                    return true;
                },
                stopEvent: (event) => event.target === handle,
                ignoreMutation: () => true,
            };
        };
    },
});

// Video links become a provider + id token. The forum server renders the player;
// the editor shows a lightweight card instead of loading the player while typing.
const parseMediaUrl = (rawValue) => {
    let url;
    try {
        url = new URL(rawValue.trim());
    } catch {
        return null;
    }
    if (url.protocol !== 'https:' && url.protocol !== 'http:') return null;
    const host = url.hostname.toLowerCase().replace(/^(?:www\.|m\.)/, '');
    const parts = url.pathname.split('/').filter(Boolean);
    const youtubeId = (value) => (/^[A-Za-z0-9_-]{11}$/.test(value ?? '') ? value : null);

    if (host === 'youtu.be') {
        const id = youtubeId(parts[0]);
        return id ? { provider: 'youtube', mediaId: id } : null;
    }
    if (host === 'youtube.com' || host === 'youtube-nocookie.com' || host === 'music.youtube.com') {
        const id = parts[0] === 'watch'
            ? youtubeId(url.searchParams.get('v'))
            : (['shorts', 'embed', 'live', 'v'].includes(parts[0]) ? youtubeId(parts[1]) : null);
        return id ? { provider: 'youtube', mediaId: id } : null;
    }
    if (host === 'clips.twitch.tv') {
        const slug = parts[0] === 'embed' ? url.searchParams.get('clip') : parts[0];
        return /^[A-Za-z0-9_-]{1,100}$/.test(slug ?? '') ? { provider: 'twitch', mediaId: `clip:${slug}` } : null;
    }
    if (host === 'twitch.tv') {
        if (parts[0] === 'videos' && /^\d{1,12}$/.test(parts[1] ?? '')) return { provider: 'twitch', mediaId: `video:${parts[1]}` };
        if (parts[1] === 'clip' && /^[A-Za-z0-9_-]{1,100}$/.test(parts[2] ?? '')) return { provider: 'twitch', mediaId: `clip:${parts[2]}` };
        if (parts.length === 1 && /^[A-Za-z0-9_]{3,25}$/.test(parts[0])) return { provider: 'twitch', mediaId: `channel:${parts[0]}` };
    }

    return null;
};

const MediaEmbed = Node.create({
    name: 'mediaEmbed',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            provider: { default: null },
            mediaId: { default: null },
            align: { default: 'left' },
        };
    },

    parseHTML() {
        return [{
            tag: 'figure[data-media]',
            priority: 70,
            getAttrs: (element) => {
                const provider = element.getAttribute('data-media');
                const mediaId = element.getAttribute('data-media-id') ?? '';
                const align = element.getAttribute('data-align')?.toLowerCase() ?? 'left';
                const valid = provider === 'youtube'
                    ? /^[A-Za-z0-9_-]{11}$/.test(mediaId)
                    : provider === 'twitch' && /^(?:video:\d{1,12}|clip:[A-Za-z0-9_-]{1,100}|channel:[A-Za-z0-9_]{3,25})$/.test(mediaId);

                return valid ? { provider, mediaId, align: SAFE_IMAGE_ALIGNMENTS.includes(align) ? align : 'left' } : false;
            },
        }];
    },

    renderHTML({ HTMLAttributes }) {
        const attributes = { 'data-media': HTMLAttributes.provider, 'data-media-id': HTMLAttributes.mediaId };
        if (['center', 'right'].includes(HTMLAttributes.align)) attributes['data-align'] = HTMLAttributes.align;

        return ['figure', attributes];
    },

    addNodeView() {
        return ({ node }) => {
            const card = document.createElement('figure');
            card.contentEditable = 'false';
            const paint = (current) => {
                card.className = `media-embed-card media-embed-${current.attrs.provider}`;
                card.dataset.align = current.attrs.align ?? 'left';
                card.replaceChildren();
                if (current.attrs.provider === 'youtube') {
                    const thumb = document.createElement('img');
                    thumb.src = `https://i.ytimg.com/vi/${current.attrs.mediaId}/hqdefault.jpg`;
                    thumb.alt = '';
                    thumb.referrerPolicy = 'no-referrer';
                    card.append(thumb);
                }
                const label = document.createElement('span');
                label.className = 'media-embed-label';
                label.textContent = `▶ ${current.attrs.provider === 'youtube' ? 'YouTube' : 'Twitch'} · ${String(current.attrs.mediaId).replace(/^(video|clip|channel):/, '')}`;
                card.append(label);
            };
            paint(node);

            return {
                dom: card,
                update: (updated) => {
                    if (updated.type.name !== 'mediaEmbed') return false;
                    paint(updated);
                    return true;
                },
                ignoreMutation: () => true,
            };
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
    const linkText = root.querySelector('[data-editor-link-text]');
    const linkStyle = root.querySelector('[data-editor-link-style]');
    const pageBlocks = root.dataset.pageBlocks === '1';
    const linkImages = root.dataset.linkImages === '1';
    const linkImageDialog = root.querySelector('[data-editor-link-image-dialog]');
    const linkImageUrl = root.querySelector('[data-editor-link-image-url]');
    const linkImageAlt = root.querySelector('[data-editor-link-image-alt]');
    const linkImagePlacement = root.querySelector('[data-editor-link-image-placement]');
    const linkImagePreview = root.querySelector('[data-editor-link-image-preview]');
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
        // The admin CSP admits inline <style> only with the response nonce Livewire carries.
        injectNonce: document.querySelector('style[data-livewire-style]')?.nonce || undefined,
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
            ...(linkImages ? [LinkImage, InlineLinkImage, MediaEmbed] : [FigureImage]),
            ...(pageBlocks ? [ContentCards, ContentCard, ContentButtons] : []),
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

    // Lets page scripts append content (for example a forum quote) through the editor schema.
    root.addEventListener('rich-editor:insert', (event) => {
        const html = event.detail?.html;
        if (typeof html === 'string' && html !== '') {
            editor.chain().focus('end').insertContent(html).run();
        }
    }, { signal });

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
            if (['figureImage', 'linkImage', 'inlineLinkImage', 'mediaEmbed', 'horizontalRule', 'table'].includes(node.type.name)) {
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
            const blockMedia = linkImages && (editor.isActive('linkImage') ? 'linkImage' : (editor.isActive('mediaEmbed') ? 'mediaEmbed' : null));
            const active = blockMedia
                ? editor.isActive(blockMedia, { align: button.dataset.editorAlign })
                : editor.isActive({ textAlign: button.dataset.editorAlign });
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
        root.querySelector('[data-editor-card-tools]')?.toggleAttribute('hidden', !editor.isActive('contentCards'));
    };

    const newCard = () => ({ type: 'contentCard', content: [
        { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: root.dataset.cardTitle }] },
        { type: 'paragraph', content: [{ type: 'text', text: root.dataset.cardDescription }] },
        { type: 'paragraph', content: [{ type: 'text', text: root.dataset.cardButton,
            marks: [{ type: 'link', attrs: { href: '#', buttonStyle: 'primary' } }] }] },
    ] });
    const cardDepth = () => {
        const { $from } = editor.state.selection;
        for (let depth = $from.depth; depth > 0; depth -= 1) {
            if ($from.node(depth).type.name === 'contentCard') return depth;
        }
        return 0;
    };
    const editCards = (action) => {
        if (!pageBlocks) return;
        const { $from } = editor.state.selection;
        const depth = cardDepth();
        if (action === 'cards') {
            const position = $from.depth ? $from.after(1) : editor.state.doc.content.size;
            editor.chain().focus().insertContentAt(position, [
                { type: 'contentCards', content: [newCard(), newCard()] }, { type: 'paragraph' },
            ]).run();
        } else if (depth && action === 'addCard') {
            editor.chain().focus().insertContentAt($from.after(depth), newCard()).run();
        } else if (depth && action === 'deleteCard') {
            const target = $from.node(depth - 1).childCount === 1 ? depth - 1 : depth;
            editor.chain().focus().deleteRange({ from: $from.before(target), to: $from.after(target) }).run();
        }
    };

    const runAction = (action) => {
        if (['cards', 'addCard', 'deleteCard'].includes(action)) {
            editCards(action);
            return;
        }
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
        if (alignButton && linkImages && (editor.isActive('linkImage') || editor.isActive('mediaEmbed'))) {
            const type = editor.isActive('linkImage') ? 'linkImage' : 'mediaEmbed';
            editor.chain().focus().updateAttributes(type, { align: alignButton.dataset.editorAlign }).run();
            return;
        }
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

        const emojiButton = event.target.closest('[data-editor-emoji]');
        if (emojiButton) {
            editor.chain().focus().insertContent(emojiButton.dataset.editorEmoji ?? '').run();
            emojiButton.closest('details')?.removeAttribute('open');
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

        if (linkText && editor.isActive('link')) {
            editor.chain().extendMarkRange('link').run();
        }
        linkUrl.value = editor.getAttributes('link').href ?? '';
        if (linkStyle) linkStyle.value = editor.getAttributes('link').buttonStyle ?? '';
        if (linkText) {
            const { from, to } = editor.state.selection;
            linkText.value = editor.state.doc.textBetween(from, to, ' ');
        }
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

        const buttonStyle = linkStyle?.value || null;
        // A link dialog with a text field can name the link ("click here") instead of
        // showing the bare address; the text replaces the current selection.
        const { from, to } = editor.state.selection;
        const label = linkText ? linkText.value.trim() : '';
        const selected = editor.state.doc.textBetween(from, to, ' ');
        if (url === '' && !buttonStyle) {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
        } else if (linkText && (label !== '' ? label !== selected : from === to)) {
            editor.chain().focus().insertContentAt({ from, to }, {
                type: 'text',
                text: label !== '' ? label : url,
                marks: [{ type: 'link', attrs: { href: url } }],
            }).run();
        } else {
            editor.chain().focus().extendMarkRange('link').setLink({ href: url || '#', ...(pageBlocks ? { buttonStyle } : {}) }).run();
        }

        linkDialog?.close();
    }, { signal });

    // A selected image or video must not be replaced by the next inserted block.
    const insertBlockAfterSelection = (content) => {
        const { selection } = editor.state;
        const chain = editor.chain().focus();
        if (selection.node) {
            chain.insertContentAt(selection.to, content).run();
        } else {
            chain.insertContent(content).run();
        }
    };

    // The server enforces the same host list; this only gives an early, friendly answer.
    const imageHosts = (root.dataset.imageHosts ?? '').split(',').filter(Boolean);
    const allowedImageUrl = (value) => {
        try {
            const url = new URL(value);
            return url.protocol === 'https:' && url.port === '' && imageHosts.includes(url.hostname.toLowerCase());
        } catch {
            return false;
        }
    };

    // People paste what image hosts give them: a direct link, a viewer page link, or an
    // HTML / BBCode / Markdown snippet. Find the direct image address in the browser; the
    // server only ever receives (and re-checks) that final allowed address.
    const extractAllowedImage = (text) => {
        for (const match of text.matchAll(/https:\/\/[^\s"'<>\[\]()]+/g)) {
            if (allowedImageUrl(match[0])) return match[0];
        }
        return null;
    };
    const viewerCandidates = (text) => {
        let url;
        try {
            url = new URL(text.trim());
        } catch {
            return [];
        }
        const host = url.hostname.toLowerCase().replace(/^www\./, '');
        const parts = url.pathname.split('/').filter(Boolean);
        if (host === 'freeimage.host' && parts[0] === 'i' && parts[1]) {
            const id = parts[1].split('.').pop();
            return /^[A-Za-z0-9]{4,20}$/.test(id) ? ['png', 'jpg', 'jpeg', 'webp', 'gif'].map((ext) => `https://iili.io/${id}.${ext}`) : [];
        }
        if (host === 'imgur.com' && parts.length === 1 && /^[A-Za-z0-9]{5,10}$/.test(parts[0])) {
            return [`https://i.imgur.com/${parts[0]}.jpg`];
        }
        return [];
    };
    const imageLoads = (url) => new Promise((resolve) => {
        const probe = new Image();
        const timer = window.setTimeout(() => resolve(false), 8000);
        probe.referrerPolicy = 'no-referrer';
        probe.onload = () => { window.clearTimeout(timer); resolve(true); };
        probe.onerror = () => { window.clearTimeout(timer); resolve(false); };
        probe.src = url;
    });
    const resolveImageInput = async (text) => {
        const direct = extractAllowedImage(text);
        if (direct) return direct;
        for (const candidate of viewerCandidates(text)) {
            if (await imageLoads(candidate)) return candidate;
        }
        return null;
    };

    let previewToken = 0;
    let resolvedImage = null;
    const previewLinkImage = async () => {
        if (!linkImagePreview || !linkImageUrl) return null;
        const token = ++previewToken;
        const text = linkImageUrl.value.trim();
        resolvedImage = null;
        linkImagePreview.replaceChildren();
        linkImagePreview.dataset.state = text === '' ? '' : 'loading';
        if (text === '') return null;

        const url = await resolveImageInput(text);
        if (token !== previewToken) return null;
        if (!url) {
            linkImagePreview.dataset.state = 'blocked';
            return null;
        }
        const image = document.createElement('img');
        image.referrerPolicy = 'no-referrer';
        image.alt = '';
        image.addEventListener('load', () => { if (token === previewToken) linkImagePreview.dataset.state = 'ready'; }, { once: true });
        image.addEventListener('error', () => { if (token === previewToken) linkImagePreview.dataset.state = 'error'; }, { once: true });
        image.src = url;
        linkImagePreview.append(image);
        resolvedImage = url;

        return url;
    };

    const selectedForumImage = () => {
        const type = editor.state.selection.node?.type.name;
        return type === 'linkImage' || type === 'inlineLinkImage' ? type : null;
    };

    root.querySelector('[data-editor-link-image]')?.addEventListener('click', () => {
        if (!linkImageDialog || !linkImageUrl) return;
        const type = selectedForumImage();
        const current = type ? editor.getAttributes(type) : null;
        linkImageUrl.value = current?.src ?? '';
        if (linkImageAlt) linkImageAlt.value = current?.alt ?? '';
        if (linkImagePlacement) {
            const { parent } = editor.state.selection.$to;
            const acceptsInlineImage = parent.type.contentMatch.matchType(editor.schema.nodes.inlineLinkImage) !== null;
            linkImagePlacement.querySelector('option[value="inline"]').disabled = !acceptsInlineImage && type !== 'inlineLinkImage';
            const inText = type === null && acceptsInlineImage && parent.textContent.trim() !== '';
            linkImagePlacement.value = type === 'inlineLinkImage' || inText ? 'inline' : 'block';
            linkImagePlacement.disabled = type !== null;
        }
        previewLinkImage();
        linkImageDialog.showModal();
        linkImageUrl.focus();
    }, { signal });

    linkImageUrl?.addEventListener('input', previewLinkImage, { signal });

    root.querySelector('[data-editor-link-image-apply]')?.addEventListener('click', async (event) => {
        event.preventDefault();
        const url = resolvedImage ?? (await previewLinkImage());
        if (!url || !allowedImageUrl(url) || url.length > 2048) {
            if (linkImagePreview) linkImagePreview.dataset.state = 'blocked';
            linkImageUrl?.focus();
            return;
        }
        const attributes = { src: url, alt: linkImageAlt?.value.trim() ?? '' };
        const type = selectedForumImage();
        if (type) {
            editor.chain().focus().updateAttributes(type, attributes).run();
        } else if (linkImagePlacement?.value === 'inline') {
            editor.chain().focus().insertContentAt(editor.state.selection.to, {
                type: 'inlineLinkImage',
                attrs: attributes,
            }).run();
        } else {
            insertBlockAfterSelection({ type: 'linkImage', attrs: { ...attributes, align: 'left', width: '100' } });
        }
        setStatus('');
        linkImageDialog?.close();
    }, { signal });

    const mediaDialog = root.querySelector('[data-editor-media-dialog]');
    const mediaUrl = root.querySelector('[data-editor-media-url]');
    root.querySelector('[data-editor-media]')?.addEventListener('click', () => {
        if (!mediaDialog || !mediaUrl) return;
        mediaUrl.value = '';
        mediaDialog.showModal();
        mediaUrl.focus();
    }, { signal });

    root.querySelector('[data-editor-media-apply]')?.addEventListener('click', (event) => {
        event.preventDefault();
        const media = parseMediaUrl(mediaUrl?.value ?? '');
        if (!media) {
            setStatus(root.dataset.unsupportedMediaMessage ?? 'This video link is not supported.', 'error');
            mediaUrl?.focus();
            return;
        }
        insertBlockAfterSelection({ type: 'mediaEmbed', attrs: { ...media, align: 'left' } });
        setStatus('');
        mediaDialog?.close();
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
