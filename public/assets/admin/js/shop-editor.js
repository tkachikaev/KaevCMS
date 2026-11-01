(() => {
    'use strict';

    // Delegation survives Livewire navigation without injecting inline handlers.
    if (document.documentElement.dataset.shopEditorInstalled === '1') {
        return;
    }
    document.documentElement.dataset.shopEditorInstalled = '1';

    const show = (dialog) => {
        if (dialog instanceof HTMLDialogElement && !dialog.open) {
            dialog.showModal();
            dialog.querySelector('input:not([type="hidden"]), select')?.focus({ preventScroll: true });
        }
    };
    const close = (dialog) => {
        if (dialog instanceof HTMLDialogElement && dialog.open) {
            dialog.close();
        }
    };
    const renumber = (form) => {
        form.querySelectorAll('[data-shop-item-row]').forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(/items\[\d+\]/, `items[${index}]`);
            });
            row.querySelectorAll('[id], [for]').forEach((element) => {
                if (element.hasAttribute('id')) element.id = element.id.replace(/-\d+$/, `-${index}`);
                if (element.hasAttribute('for')) element.setAttribute('for', element.getAttribute('for').replace(/-\d+$/, `-${index}`));
            });
        });
    };
    const openErroredDialog = () => {
        document.querySelectorAll('[data-shop-server-page] [data-shop-dialog][data-open-on-error="1"]')
            .forEach((dialog) => show(dialog));
    };
    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        const trigger = event.target.closest('[data-shop-open]');
        if (trigger) {
            show(document.getElementById(trigger.getAttribute('data-shop-open')));
            return;
        }
        const dismiss = event.target.closest('[data-shop-close]');
        if (dismiss) {
            close(dismiss.closest('dialog'));
            return;
        }
        const add = event.target.closest('[data-shop-add-item]');
        if (add) {
            const form = add.closest('[data-shop-product-form]');
            const rows = form?.querySelector('[data-shop-rows]');
            const template = form?.querySelector('[data-shop-row-template]');
            if (!rows || !(template instanceof HTMLTemplateElement)) return;
            const count = rows.querySelectorAll('[data-shop-item-row]').length;
            if (count >= 100) return;
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-shop-item-row]');
            if (!row) return;
            row.querySelectorAll('[id], [for]').forEach((element) => {
                if (element.hasAttribute('id')) element.id = element.id.replace(/-new$/, `-${count}`);
                if (element.hasAttribute('for')) element.setAttribute('for', element.getAttribute('for').replace(/-new$/, `-${count}`));
            });
            row.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace('__INDEX__', String(count));
            });
            rows.appendChild(fragment);
            renumber(form);
            rows.lastElementChild?.querySelector('input')?.focus();
            return;
        }
        const remove = event.target.closest('[data-shop-remove-item]');
        if (remove) {
            const form = remove.closest('[data-shop-product-form]');
            const rows = form?.querySelector('[data-shop-rows]');
            if (!rows || rows.querySelectorAll('[data-shop-item-row]').length <= 1) return;
            remove.closest('[data-shop-item-row]')?.remove();
            renumber(form);
        }
    });
    document.addEventListener('livewire:navigated', openErroredDialog);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openErroredDialog, { once: true });
    } else {
        openErroredDialog();
    }
})();
