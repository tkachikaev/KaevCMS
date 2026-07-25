(() => {
    'use strict';

    const initialize = () => {
        const editor = document.querySelector('[data-daily-reward-editor]');
        if (!editor) {
            return undefined;
        }

        const abortController = new AbortController();
        const { signal } = abortController;
        const template = editor.querySelector('[data-daily-item-template]');
        const maxRows = Number.parseInt(editor.dataset.maxRows ?? '100', 10);

        const dayElements = () => Array.from(editor.querySelectorAll('[data-daily-day]'));
        const rowsFor = (day) => Array.from(day.querySelectorAll('[data-daily-item-row]'));

        const synchronizeDay = (day) => {
            const dayNumber = day.dataset.dayNumber;
            const rows = rowsFor(day);

            rows.forEach((row, index) => {
                const item = row.querySelector('[data-daily-item-id]');
                const amount = row.querySelector('[data-daily-item-amount]');
                if (item) {
                    item.name = `days[${dayNumber}][rewards][${index}][item_id]`;
                }
                if (amount) {
                    amount.name = `days[${dayNumber}][rewards][${index}][amount]`;
                }
            });

            rows.forEach((row) => {
                const remove = row.querySelector('[data-daily-item-remove]');
                if (remove) {
                    remove.hidden = rows.length <= 1;
                }
            });

            const add = day.querySelector('[data-daily-item-add]');
            if (add) {
                add.disabled = rows.length >= maxRows;
            }
        };

        const valuesFrom = (day) => rowsFor(day)
            .map((row) => ({
                itemId: row.querySelector('[data-daily-item-id]')?.value ?? '',
                amount: row.querySelector('[data-daily-item-amount]')?.value ?? '',
            }))
            .filter((row) => row.itemId !== '' || row.amount !== '');

        const replaceRows = (day, values) => {
            const list = day.querySelector('[data-daily-item-list]');
            if (!list || !template) {
                return;
            }

            list.innerHTML = '';
            const source = values.length > 0 ? values : [{ itemId: '', amount: '' }];
            source.forEach((value) => {
                const fragment = template.content.cloneNode(true);
                const row = fragment.querySelector('[data-daily-item-row]');
                const item = row?.querySelector('[data-daily-item-id]');
                const amount = row?.querySelector('[data-daily-item-amount]');
                if (item) item.value = value.itemId;
                if (amount) amount.value = value.amount;
                list.append(fragment);
            });
            synchronizeDay(day);
        };

        editor.addEventListener('click', (event) => {
            const addButton = event.target.closest('[data-daily-item-add]');
            if (addButton) {
                const day = addButton.closest('[data-daily-day]');
                const list = day?.querySelector('[data-daily-item-list]');
                if (!day || !list || !template || rowsFor(day).length >= maxRows) {
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
                if (!day || !row) {
                    return;
                }

                if (rowsFor(day).length <= 1) {
                    row.querySelectorAll('input').forEach((input) => { input.value = ''; });
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
                const previous = dayElements().find((candidate) => Number.parseInt(candidate.dataset.dayNumber ?? '0', 10) === dayNumber - 1);
                if (!day || !previous) {
                    return;
                }

                replaceRows(day, valuesFrom(previous));
                const enabled = day.querySelector('[data-daily-day-enabled]');
                const previousEnabled = previous.querySelector('[data-daily-day-enabled]');
                if (enabled && previousEnabled) enabled.checked = previousEnabled.checked;
                day.open = true;
                return;
            }

            const copyEmpty = event.target.closest('[data-daily-copy-empty]');
            if (copyEmpty) {
                const sourceDay = copyEmpty.closest('[data-daily-day]');
                if (!sourceDay) {
                    return;
                }

                const values = valuesFrom(sourceDay);
                if (values.length === 0) {
                    return;
                }

                dayElements().forEach((targetDay) => {
                    if (targetDay === sourceDay || targetDay.querySelector('[data-daily-day-enabled]')?.disabled) {
                        return;
                    }
                    if (valuesFrom(targetDay).length > 0) {
                        return;
                    }

                    replaceRows(targetDay, values);
                    const enabled = targetDay.querySelector('[data-daily-day-enabled]');
                    if (enabled) enabled.checked = true;
                });
            }
        }, { signal });

        dayElements().forEach(synchronizeDay);

        return () => abortController.abort();
    };

    window.KaevCMSAdmin.registerPage('daily-rewards', initialize);
})();
