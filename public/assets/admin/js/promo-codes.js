(() => {
    'use strict';

    const initialize = () => {
        const abortController = new AbortController();
        const { signal } = abortController;
        let initialized = false;

        document.querySelectorAll('[data-promo-delete-form]').forEach((form) => {
            initialized = true;
            form.addEventListener('submit', (event) => {
                const message = form.dataset.confirmMessage;

                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            }, { signal });
        });

        const editor = document.querySelector('[data-promo-rewards-editor]');
        if (editor) {
            const list = editor.querySelector('[data-promo-reward-list]');
            const template = editor.querySelector('[data-promo-reward-template]');
            const addButton = editor.querySelector('[data-promo-reward-add]');
            const message = editor.querySelector('[data-promo-reward-message]');
            const maxRows = Number.parseInt(editor.dataset.maxRows ?? '100', 10);
            const previewUrlTemplate = editor.dataset.previewUrl ?? '';
            const unknownItem = editor.dataset.unknownItem ?? 'Unknown item';
            const enterItem = editor.dataset.enterItem ?? 'Enter an item ID';
            const serverSelect = document.querySelector('#game_server_id');
            const previewTimers = new WeakMap();

            if (list && template && addButton) {
                initialized = true;

                const rows = () => Array.from(list.querySelectorAll('[data-promo-reward-row]'));

                const fillPreview = (row, payload = {}) => {
                    const preview = row.querySelector('[data-promo-reward-preview]');
                    const name = row.querySelector('[data-promo-reward-name]');
                    if (preview instanceof HTMLElement) {
                        preview.innerHTML = '';
                        if (payload.iconUrl) {
                            const image = document.createElement('img');
                            image.src = payload.iconUrl;
                            image.alt = '';
                            image.width = 48;
                            image.height = 48;
                            preview.append(image);
                        } else {
                            const fallback = document.createElement('i');
                            fallback.textContent = '◇';
                            preview.append(fallback);
                        }
                    }
                    if (name instanceof HTMLElement) {
                        name.textContent = payload.name || enterItem;
                    }
                };

                const fetchPreview = async (input) => {
                    const row = input.closest('[data-promo-reward-row]');
                    const serverId = Number.parseInt(serverSelect?.value ?? '', 10);
                    const itemId = Number.parseInt(input.value, 10);
                    if (!(row instanceof HTMLElement)) {
                        return;
                    }
                    if (!Number.isInteger(serverId) || serverId < 1 || !Number.isInteger(itemId) || itemId < 1 || previewUrlTemplate === '') {
                        fillPreview(row);
                        return;
                    }

                    try {
                        const url = previewUrlTemplate
                            .replace('__SERVER__', encodeURIComponent(String(serverId)))
                            .replace('__ITEM__', encodeURIComponent(String(itemId)));
                        const response = await fetch(url, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                            signal,
                        });
                        if (!response.ok) {
                            throw new Error('preview_failed');
                        }
                        const payload = await response.json();
                        if (Number.parseInt(input.value, 10) !== itemId || Number.parseInt(serverSelect?.value ?? '', 10) !== serverId) {
                            return;
                        }
                        fillPreview(row, {
                            name: payload.name ?? unknownItem,
                            iconUrl: payload.icon_url ?? '',
                        });
                    } catch (error) {
                        if (error?.name !== 'AbortError') {
                            fillPreview(row, { name: unknownItem });
                        }
                    }
                };

                const schedulePreview = (input) => {
                    const previousTimer = previewTimers.get(input);
                    if (previousTimer) {
                        window.clearTimeout(previousTimer);
                    }
                    previewTimers.set(input, window.setTimeout(() => fetchPreview(input), 250));
                };

                const synchronizeRows = () => {
                    const currentRows = rows();

                    currentRows.forEach((row, index) => {
                        const item = row.querySelector('[data-promo-reward-item]');
                        const amount = row.querySelector('[data-promo-reward-amount]');
                        const itemLabel = item?.closest('.form-group')?.querySelector('label');
                        const amountLabel = amount?.closest('.form-group')?.querySelector('label');

                        if (item) {
                            item.id = `reward_item_${index}`;
                            item.name = `rewards[${index}][item_id]`;
                        }

                        if (amount) {
                            amount.id = `reward_amount_${index}`;
                            amount.name = `rewards[${index}][amount]`;
                        }

                        if (itemLabel && item) {
                            itemLabel.htmlFor = item.id;
                        }

                        if (amountLabel && amount) {
                            amountLabel.htmlFor = amount.id;
                        }
                    });

                    const onlyOneRow = currentRows.length <= 1;
                    currentRows.forEach((row) => {
                        const removeButton = row.querySelector('[data-promo-reward-remove]');

                        if (removeButton) {
                            removeButton.hidden = onlyOneRow;
                        }
                    });

                    const atLimit = currentRows.length >= maxRows;
                    addButton.disabled = atLimit;
                    if (message) {
                        message.textContent = atLimit
                            ? editor.dataset.limitMessage ?? ''
                            : message.dataset.defaultMessage ?? message.textContent;
                    }
                };

                if (message) {
                    message.dataset.defaultMessage = message.textContent ?? '';
                }

                addButton.addEventListener('click', () => {
                    if (rows().length >= maxRows) {
                        return;
                    }

                    const fragment = template.content.cloneNode(true);
                    list.append(fragment);
                    synchronizeRows();
                    rows().at(-1)?.querySelector('[data-promo-reward-item]')?.focus();
                }, { signal });

                list.addEventListener('click', (event) => {
                    const removeButton = event.target.closest('[data-promo-reward-remove]');
                    if (!removeButton) {
                        return;
                    }

                    const currentRows = rows();
                    const row = removeButton.closest('[data-promo-reward-row]');
                    if (!row) {
                        return;
                    }

                    if (currentRows.length <= 1) {
                        row.querySelectorAll('input').forEach((input) => {
                            input.value = '';
                        });
                        fillPreview(row);
                    } else {
                        row.remove();
                    }

                    synchronizeRows();
                }, { signal });

                list.addEventListener('input', (event) => {
                    if (event.target instanceof HTMLInputElement && event.target.matches('[data-promo-reward-item]')) {
                        schedulePreview(event.target);
                    }
                }, { signal });

                if (serverSelect instanceof HTMLSelectElement) {
                    serverSelect.addEventListener('change', () => {
                        rows().forEach((row) => {
                            const input = row.querySelector('[data-promo-reward-item]');
                            if (input instanceof HTMLInputElement) {
                                schedulePreview(input);
                            }
                        });
                    }, { signal });
                }

                synchronizeRows();
                rows().forEach((row) => {
                    const input = row.querySelector('[data-promo-reward-item]');
                    const image = row.querySelector('[data-promo-reward-preview] img');
                    if (input instanceof HTMLInputElement && input.value.trim() !== '' && !(image instanceof HTMLImageElement)) {
                        schedulePreview(input);
                    }
                });
            }
        }

        return initialized ? () => abortController.abort() : undefined;
    };

    window.KaevCMSAdmin.registerPage('promo-codes', initialize);
})();
