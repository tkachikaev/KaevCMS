(() => {
    'use strict';

    // Disabling a module asks for confirmation first. The Disable button stays a real submit
    // button, so without this script the form still works.
    const initialize = () => {
        const dialog = document.querySelector('[data-module-disable-dialog]');
        const title = dialog?.querySelector('[data-module-disable-title]');
        const cancelButton = dialog?.querySelector('[data-module-disable-cancel]');
        const confirmButton = dialog?.querySelector('[data-module-disable-confirm]');
        const openButtons = document.querySelectorAll('[data-module-disable-open]');

        if (!(dialog instanceof HTMLDialogElement) || !(confirmButton instanceof HTMLButtonElement) || openButtons.length === 0) return;

        let pendingForm = null;

        openButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                const form = button.closest('form');
                if (!(form instanceof HTMLFormElement)) return;

                event.preventDefault();
                pendingForm = form;
                if (title) title.textContent = button.dataset.moduleDisableTitle ?? '';
                dialog.showModal();
            });
        });

        confirmButton.addEventListener('click', () => {
            const form = pendingForm;
            dialog.close();
            if (form) {
                confirmButton.disabled = true;
                form.submit();
            }
        });
        cancelButton?.addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', () => {
            pendingForm = null;
            if (title) title.textContent = '';
        });
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('module-actions', initialize);
    } else {
        initialize();
    }
})();
