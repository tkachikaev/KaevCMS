(() => {
    'use strict';

    const pageName = 'daily-rewards';

    const initialize = () => {
        const editor = document.querySelector('[data-daily-reward-editor]');
        if (!(editor instanceof HTMLFormElement)) {
            return undefined;
        }

        const abortController = new AbortController();
        const { signal } = abortController;
        const template = editor.querySelector('[data-daily-item-template]');
        const maxRows = Number.parseInt(editor.dataset.maxRows ?? '100', 10);
        const previewUrlTemplate = editor.dataset.previewUrl ?? '';
        const unknownItem = editor.dataset.unknownItem ?? 'Unknown item';
        const noReward = editor.dataset.noReward ?? 'No reward';
        const activeLabel = editor.dataset.activeLabel ?? 'Active';
        const inactiveLabel = editor.dataset.inactiveLabel ?? 'Inactive';
        const previewTimers = new WeakMap();

        const dayElements = () => Array.from(editor.querySelectorAll('[data-daily-day]'));
        const rowsFor = (day) => Array.from(day.querySelectorAll('[data-daily-item-row]'));
        const dayByNumber = (number) => dayElements().find((day) => day.dataset.dayNumber === String(number));
        const tileFor = (day) => editor.querySelector(`[data-daily-day-tile="${day.dataset.dayNumber}"]`);

        const closeDay = (dialog) => {
            if (dialog instanceof HTMLDialogElement && dialog.open) {
                dialog.close();
            }
            document.documentElement.classList.remove('admin-modal-open');
        };

        const openDay = (dialog) => {
            if (!(dialog instanceof HTMLDialogElement)) {
                return;
            }

            if (!dialog.open) {
                dialog.showModal();
            }
            document.documentElement.classList.add('admin-modal-open');
            dialog.querySelector('[data-daily-item-id]:not([disabled])')?.focus({ preventScroll: true });
        };

        const configuredRows = (day) => rowsFor(day).filter((row) => {
            const itemId = row.querySelector('[data-daily-item-id]')?.value.trim() ?? '';
            return itemId !== '' && Number.parseInt(itemId, 10) > 0;
        });

        const updateTile = (day) => {
            const tile = tileFor(day);
            if (!(tile instanceof HTMLButtonElement)) {
                return;
            }

            const enabled = day.querySelector('[data-daily-day-enabled]')?.checked === true;
            tile.classList.toggle('is-enabled', enabled);
            tile.querySelector('[data-daily-tile-state]').textContent = enabled ? activeLabel : inactiveLabel;

            const iconContainer = tile.querySelector('[data-daily-tile-icons]');
            if (!(iconContainer instanceof HTMLElement)) {
                return;
            }

            const rows = configuredRows(day);
            iconContainer.innerHTML = '';
            if (rows.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'daily-reward-admin-calendar-empty';
                empty.textContent = noReward;
                iconContainer.append(empty);
                return;
            }

            rows.slice(0, 3).forEach((row) => {
                const item = document.createElement('span');
                item.className = 'daily-reward-admin-calendar-icon';
                const previewImage = row.querySelector('[data-daily-item-preview] img');
                if (previewImage instanceof HTMLImageElement) {
                    const image = document.createElement('img');
                    image.src = previewImage.src;
                    image.alt = '';
                    image.width = 38;
                    image.height = 38;
                    item.append(image);
                } else {
                    const fallback = document.createElement('i');
                    fallback.setAttribute('aria-hidden', 'true');
                    fallback.textContent = '◇';
                    item.append(fallback);
                }

                const amount = document.createElement('b');
                amount.textContent = `× ${row.querySelector('[data-daily-item-amount]')?.value || '0'}`;
                item.append(amount);
                iconContainer.append(item);
            });

            if (rows.length > 3) {
                const more = document.createElement('span');
                more.className = 'daily-reward-admin-calendar-more';
                more.textContent = `+${rows.length - 3}`;
                iconContainer.append(more);
            }
        };

        const synchronizeDay = (day) => {
            const dayNumber = day.dataset.dayNumber;
            const rows = rowsFor(day);

            rows.forEach((row, index) => {
                const item = row.querySelector('[data-daily-item-id]');
                const amount = row.querySelector('[data-daily-item-amount]');
                if (item instanceof HTMLInputElement && !item.disabled) {
                    item.name = `days[${dayNumber}][rewards][${index}][item_id]`;
                }
                if (amount instanceof HTMLInputElement && !amount.disabled) {
                    amount.name = `days[${dayNumber}][rewards][${index}][amount]`;
                }
            });

            rows.forEach((row) => {
                const remove = row.querySelector('[data-daily-item-remove]');
                if (remove instanceof HTMLButtonElement) {
                    remove.hidden = rows.length <= 1;
                }
            });

            const add = day.querySelector('[data-daily-item-add]');
            if (add instanceof HTMLButtonElement) {
                add.disabled = rows.length >= maxRows;
            }

            updateTile(day);
        };

        const valuesFrom = (day) => rowsFor(day)
            .map((row) => ({
                itemId: row.querySelector('[data-daily-item-id]')?.value ?? '',
                amount: row.querySelector('[data-daily-item-amount]')?.value ?? '',
                name: row.querySelector('[data-daily-item-name]')?.textContent ?? '',
                iconUrl: row.querySelector('[data-daily-item-preview] img')?.src ?? '',
            }))
            .filter((row) => row.itemId !== '' || row.amount !== '');

        const fillPreview = (row, value) => {
            const preview = row.querySelector('[data-daily-item-preview]');
            const name = row.querySelector('[data-daily-item-name]');
            if (preview instanceof HTMLElement) {
                preview.innerHTML = '';
                if (value.iconUrl) {
                    const image = document.createElement('img');
                    image.src = value.iconUrl;
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
                name.textContent = value.name || unknownItem;
            }
        };

        const replaceRows = (day, values) => {
            const list = day.querySelector('[data-daily-item-list]');
            if (!(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
                return;
            }

            list.innerHTML = '';
            const source = values.length > 0 ? values : [{ itemId: '', amount: '', name: '', iconUrl: '' }];
            source.forEach((value) => {
                const fragment = template.content.cloneNode(true);
                const row = fragment.querySelector('[data-daily-item-row]');
                const item = row?.querySelector('[data-daily-item-id]');
                const amount = row?.querySelector('[data-daily-item-amount]');
                if (item instanceof HTMLInputElement) item.value = value.itemId;
                if (amount instanceof HTMLInputElement) amount.value = value.amount;
                if (row instanceof HTMLElement) fillPreview(row, value);
                list.append(fragment);
            });
            synchronizeDay(day);
        };

        const fetchPreview = async (input) => {
            const row = input.closest('[data-daily-item-row]');
            const day = input.closest('[data-daily-day]');
            const itemId = Number.parseInt(input.value, 10);
            if (!(row instanceof HTMLElement) || !(day instanceof HTMLElement)) {
                return;
            }

            if (!Number.isInteger(itemId) || itemId < 1 || previewUrlTemplate === '') {
                fillPreview(row, { name: '', iconUrl: '' });
                updateTile(day);
                return;
            }

            try {
                const response = await fetch(previewUrlTemplate.replace('__ITEM__', encodeURIComponent(String(itemId))), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal,
                });
                if (!response.ok) {
                    throw new Error('preview_failed');
                }
                const payload = await response.json();
                if (Number.parseInt(input.value, 10) !== itemId) {
                    return;
                }
                fillPreview(row, { name: payload.name ?? unknownItem, iconUrl: payload.icon_url ?? '' });
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    fillPreview(row, { name: unknownItem, iconUrl: '' });
                }
            }
            updateTile(day);
        };

        editor.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }

            const openButton = event.target.closest('[data-daily-day-open]');
            if (openButton instanceof HTMLButtonElement) {
                openDay(document.getElementById(openButton.dataset.dailyDayOpen ?? ''));
                return;
            }

            const closeButton = event.target.closest('[data-daily-day-close]');
            if (closeButton) {
                const day = closeButton.closest('[data-daily-day]');
                if (day instanceof HTMLElement) {
                    synchronizeDay(day);
                    closeDay(day);
                }
                return;
            }

            const addButton = event.target.closest('[data-daily-item-add]');
            if (addButton) {
                const day = addButton.closest('[data-daily-day]');
                const list = day?.querySelector('[data-daily-item-list]');
                if (!(day instanceof HTMLElement) || !(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement) || rowsFor(day).length >= maxRows) {
                    return;
                }
                list.append(template.content.cloneNode(true));
                synchronizeDay(day);
                rowsFor(day).at(-1)?.querySelector('[data-daily-item-id]')?.focus();
                return;
            }

            const removeButton = event.target.closest('[data-daily-item-remove]');
            if (removeButton) {
                const day = removeButton.closest('[data-daily-day]');
                const row = removeButton.closest('[data-daily-item-row]');
                if (!(day instanceof HTMLElement) || !(row instanceof HTMLElement)) {
                    return;
                }
                if (rowsFor(day).length <= 1) {
                    row.querySelectorAll('input').forEach((input) => { input.value = ''; });
                    fillPreview(row, { name: '', iconUrl: '' });
                } else {
                    row.remove();
                }
                synchronizeDay(day);
                return;
            }

            const copyPrevious = event.target.closest('[data-daily-copy-previous]');
            if (copyPrevious) {
                const day = copyPrevious.closest('[data-daily-day]');
                const dayNumber = Number.parseInt(day?.dataset.dayNumber ?? '0', 10);
                const previous = dayByNumber(dayNumber - 1);
                if (!(day instanceof HTMLElement) || !(previous instanceof HTMLElement)) {
                    return;
                }
                replaceRows(day, valuesFrom(previous));
                const enabled = day.querySelector('[data-daily-day-enabled]');
                const previousEnabled = previous.querySelector('[data-daily-day-enabled]');
                if (enabled instanceof HTMLInputElement && previousEnabled instanceof HTMLInputElement) {
                    enabled.checked = previousEnabled.checked;
                }
                synchronizeDay(day);
                return;
            }

            const copyEmpty = event.target.closest('[data-daily-copy-empty]');
            if (copyEmpty) {
                const sourceDay = copyEmpty.closest('[data-daily-day]');
                if (!(sourceDay instanceof HTMLElement)) {
                    return;
                }
                const values = valuesFrom(sourceDay);
                if (values.length === 0) {
                    return;
                }
                dayElements().forEach((targetDay) => {
                    if (targetDay === sourceDay || targetDay.querySelector('[data-daily-day-enabled]')?.disabled || configuredRows(targetDay).length > 0) {
                        return;
                    }
                    replaceRows(targetDay, values);
                    const enabled = targetDay.querySelector('[data-daily-day-enabled]');
                    if (enabled instanceof HTMLInputElement) enabled.checked = true;
                    synchronizeDay(targetDay);
                });
            }
        }, { signal });

        editor.addEventListener('input', (event) => {
            if (!(event.target instanceof HTMLInputElement)) {
                return;
            }
            const day = event.target.closest('[data-daily-day]');
            if (!(day instanceof HTMLElement)) {
                return;
            }
            if (event.target.matches('[data-daily-item-id]')) {
                const previousTimer = previewTimers.get(event.target);
                if (previousTimer) window.clearTimeout(previousTimer);
                previewTimers.set(event.target, window.setTimeout(() => fetchPreview(event.target), 250));
            }
            updateTile(day);
        }, { signal });

        editor.addEventListener('change', (event) => {
            const day = event.target instanceof Element ? event.target.closest('[data-daily-day]') : null;
            if (day instanceof HTMLElement) updateTile(day);
        }, { signal });

        editor.addEventListener('submit', () => {
            dayElements().forEach((day) => closeDay(day));
        }, { signal });

        dayElements().forEach((day) => {
            synchronizeDay(day);
            rowsFor(day).forEach((row) => {
                const itemInput = row.querySelector('[data-daily-item-id]');
                const previewImage = row.querySelector('[data-daily-item-preview] img');
                if (itemInput instanceof HTMLInputElement && itemInput.value.trim() !== '' && !(previewImage instanceof HTMLImageElement)) {
                    fetchPreview(itemInput);
                }
            });
            day.addEventListener('click', (event) => {
                if (event.target === day) closeDay(day);
            }, { signal });
            day.addEventListener('close', () => document.documentElement.classList.remove('admin-modal-open'), { signal });
        });

        const autoOpen = editor.querySelector('[data-daily-day-auto-open]');
        if (autoOpen instanceof HTMLDialogElement) {
            openDay(autoOpen);
        }

        return () => {
            abortController.abort();
            document.documentElement.classList.remove('admin-modal-open');
        };
    };

    window.KaevCMSAdmin.registerPage(pageName, initialize);
})();
