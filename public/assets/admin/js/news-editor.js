(() => {
    'use strict';

    const initialize = () => {
        (() => {
            'use strict';

            const escapeHtml = (value) => String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');

            const initializeCoverPreview = () => {
                const wrapper = document.querySelector('[data-cover-upload]');
                if (!wrapper) return;

                const input = wrapper.querySelector('[data-cover-input]');
                const preview = wrapper.querySelector('[data-cover-preview]');
                const remove = wrapper.querySelector('[data-cover-remove]');

                if (!input || !preview) return;

                input.addEventListener('change', () => {
                    const file = input.files?.[0];
                    if (!file) return;

                    if (remove) remove.checked = false;

                    const url = URL.createObjectURL(file);
                    preview.innerHTML = `<img src="${url}" alt="${escapeHtml(wrapper.dataset.previewAlt ?? 'Selected image preview')}">`;
                    preview.classList.add('has-image');

                    const image = preview.querySelector('img');
                    image?.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
                });

                remove?.addEventListener('change', () => {
                    preview.classList.toggle('marked-for-removal', remove.checked);
                });
            };

            const initializeRichEditor = (editor) => {
                const canvas = editor.querySelector('.rich-editor-canvas');
                const source = editor.querySelector('.rich-editor-source');
                const imageInput = editor.querySelector('[data-editor-image-input]');
                const imageButton = editor.querySelector('[data-editor-image]');
                const status = editor.querySelector('[data-editor-status]');
                const uploadUrl = editor.dataset.uploadUrl;
                const form = editor.closest('form');
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                let savedRange = null;

                if (!canvas || !source || !form) return;

                document.execCommand('defaultParagraphSeparator', false, 'p');
                document.execCommand('styleWithCSS', false, false);

                if (canvas.innerHTML.trim() === '') {
                    canvas.innerHTML = '<p><br></p>';
                }

                const setStatus = (message, type = '') => {
                    if (!status) return;
                    status.textContent = message;
                    status.dataset.type = type;
                };

                const focusCanvas = () => {
                    canvas.focus();
                    if (savedRange) {
                        const selection = window.getSelection();
                        selection?.removeAllRanges();
                        selection?.addRange(savedRange);
                    }
                };

                const saveSelection = () => {
                    const selection = window.getSelection();
                    if (!selection || selection.rangeCount === 0) return;
                    const range = selection.getRangeAt(0);
                    if (canvas.contains(range.commonAncestorContainer)) {
                        savedRange = range.cloneRange();
                    }
                };

                const normalizeMarkup = () => {
                    canvas.querySelectorAll('b').forEach((node) => {
                        const replacement = document.createElement('strong');
                        replacement.innerHTML = node.innerHTML;
                        node.replaceWith(replacement);
                    });

                    canvas.querySelectorAll('i').forEach((node) => {
                        const replacement = document.createElement('em');
                        replacement.innerHTML = node.innerHTML;
                        node.replaceWith(replacement);
                    });

                    canvas.querySelectorAll('strike').forEach((node) => {
                        const replacement = document.createElement('s');
                        replacement.innerHTML = node.innerHTML;
                        node.replaceWith(replacement);
                    });

                    const colorMap = {
                        'rgb(194, 145, 67)': 'gold', '#c29143': 'gold',
                        'rgb(220, 38, 38)': 'red', '#dc2626': 'red',
                        'rgb(22, 163, 74)': 'green', '#16a34a': 'green',
                        'rgb(37, 99, 235)': 'blue', '#2563eb': 'blue',
                        'rgb(107, 114, 128)': 'muted', '#6b7280': 'muted',
                    };

                    canvas.querySelectorAll('font[color]').forEach((node) => {
                        const raw = (node.getAttribute('color') ?? '').toLowerCase();
                        const replacement = document.createElement('span');
                        const color = colorMap[raw];
                        if (color) replacement.dataset.color = color;
                        replacement.innerHTML = node.innerHTML;
                        node.replaceWith(replacement);
                    });

                    canvas.querySelectorAll('[style]').forEach((node) => node.removeAttribute('style'));
                    canvas.querySelectorAll('[class]').forEach((node) => node.removeAttribute('class'));
                };

                const syncSource = () => {
                    normalizeMarkup();
                    source.value = canvas.innerHTML.trim();
                };

                const execute = (command, value = null) => {
                    focusCanvas();
                    document.execCommand(command, false, value);
                    saveSelection();
                    syncSource();
                };

                const selectedBlock = () => {
                    const selection = window.getSelection();
                    let node = selection?.anchorNode ?? null;
                    if (node?.nodeType === Node.TEXT_NODE) node = node.parentElement;
                    if (!(node instanceof Element)) return null;

                    return node.closest('p,h2,h3,h4,blockquote,figure,pre');
                };

                editor.querySelectorAll('[data-editor-command]').forEach((button) => {
                    button.addEventListener('mousedown', (event) => event.preventDefault());
                    button.addEventListener('click', () => execute(button.dataset.editorCommand));
                });

                editor.querySelector('[data-editor-block]')?.addEventListener('change', (event) => {
                    const value = event.target.value;
                    execute('formatBlock', value);
                    event.target.value = 'p';
                });

                editor.querySelectorAll('[data-editor-align]').forEach((button) => {
                    button.addEventListener('mousedown', (event) => event.preventDefault());
                    button.addEventListener('click', () => {
                        focusCanvas();
                        const block = selectedBlock();
                        if (!block) return;

                        const alignment = button.dataset.editorAlign;
                        if (alignment === 'left') {
                            block.removeAttribute('data-align');
                        } else {
                            block.setAttribute('data-align', alignment);
                        }
                        saveSelection();
                        syncSource();
                    });
                });

                editor.querySelector('[data-editor-color]')?.addEventListener('change', (event) => {
                    const colors = {
                        default: '#111827',
                        gold: '#c29143',
                        red: '#dc2626',
                        green: '#16a34a',
                        blue: '#2563eb',
                        muted: '#6b7280',
                    };
                    execute('foreColor', colors[event.target.value] ?? colors.default);
                    event.target.value = 'default';
                });

                editor.querySelector('[data-editor-link]')?.addEventListener('mousedown', (event) => event.preventDefault());
                editor.querySelector('[data-editor-link]')?.addEventListener('click', () => {
                    saveSelection();
                    const url = window.prompt(editor.dataset.linkPrompt ?? 'Link URL:');
                    if (!url) return;
                    execute('createLink', url.trim());
                });

                const uploadImage = async (file) => {
                    if (!file || !uploadUrl) return;

                    setStatus(editor.dataset.uploadingMessage ?? 'Uploading image…');
                    const data = new FormData();
                    data.append('image', file);

                    try {
                        const response = await fetch(uploadUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: data,
                            credentials: 'same-origin',
                        });

                        const payload = await response.json().catch(() => ({}));
                        if (!response.ok || !payload.url) {
                            const errors = Object.values(payload.errors ?? {}).flat();
                            throw new Error(errors[0] ?? payload.message ?? editor.dataset.uploadFailedMessage ?? 'Could not upload the image.');
                        }

                        const alt = window.prompt(editor.dataset.imageAltPrompt ?? 'Short image description:', file.name.replace(/\.[^.]+$/, '')) ?? '';
                        const html = `<figure><img src="${escapeHtml(payload.url)}" alt="${escapeHtml(alt)}"><figcaption>${escapeHtml(alt)}</figcaption></figure><p><br></p>`;
                        focusCanvas();
                        document.execCommand('insertHTML', false, html);
                        saveSelection();
                        syncSource();
                        setStatus(editor.dataset.imageAddedMessage ?? 'Image added.', 'success');
                    } catch (error) {
                        setStatus(error instanceof Error ? error.message : (editor.dataset.uploadErrorMessage ?? 'Image upload error.'), 'error');
                    } finally {
                        if (imageInput) imageInput.value = '';
                    }
                };

                imageButton?.addEventListener('mousedown', (event) => event.preventDefault());
                imageButton?.addEventListener('click', () => {
                    saveSelection();
                    imageInput?.click();
                });
                imageInput?.addEventListener('change', () => uploadImage(imageInput.files?.[0]));

                canvas.addEventListener('keyup', saveSelection);
                canvas.addEventListener('mouseup', saveSelection);
                canvas.addEventListener('input', () => { saveSelection(); syncSource(); });
                canvas.addEventListener('blur', syncSource);

                canvas.addEventListener('paste', (event) => {
                    event.preventDefault();
                    const text = event.clipboardData?.getData('text/plain') ?? '';
                    document.execCommand('insertHTML', false, escapeHtml(text).replace(/\r?\n/g, '<br>'));
                    syncSource();
                });

                canvas.addEventListener('dragover', (event) => {
                    if ([...(event.dataTransfer?.items ?? [])].some((item) => item.kind === 'file')) {
                        event.preventDefault();
                    }
                });

                canvas.addEventListener('drop', (event) => {
                    const file = [...(event.dataTransfer?.files ?? [])].find((item) => item.type.startsWith('image/'));
                    if (!file) return;
                    event.preventDefault();
                    saveSelection();
                    uploadImage(file);
                });

                form.addEventListener('submit', (event) => {
                    syncSource();

                    const isPreview = event.submitter?.matches('[data-news-preview], [data-content-preview]') ?? false;
                    const methodOverride = form.querySelector('input[name="_method"]');

                    if (isPreview && methodOverride) {
                        methodOverride.disabled = true;
                        window.setTimeout(() => { methodOverride.disabled = false; }, 0);
                    }

                    const plainText = canvas.textContent?.replace(/\u00a0/g, ' ').trim() ?? '';
                    if (editor.dataset.required === '1' && plainText === '') {
                        event.preventDefault();
                        setStatus(editor.dataset.emptyMessage ?? 'Add text in the default language.', 'error');
                        canvas.focus();
                    }
                });

                syncSource();
            };

            initializeCoverPreview();
            document.querySelectorAll('[data-rich-editor]').forEach(initializeRichEditor);
        })();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('news-editor', initialize);
    } else {
        initialize();
    }
})();
