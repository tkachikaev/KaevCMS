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

            if (list && template && addButton) {
                initialized = true;

                const rows = () => Array.from(list.querySelectorAll('[data-promo-reward-row]'));

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
                    } else {
                        row.remove();
                    }

                    synchronizeRows();
                }, { signal });

                synchronizeRows();
            }
        }

        return initialized ? () => abortController.abort() : undefined;
    };

    window.KaevCMSAdmin.registerPage('promo-codes', initialize);
})();
