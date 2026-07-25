(() => {
    'use strict';

    const initialize = () => {
        const dialog = document.querySelector('[data-queue-delete-dialog]');
        const form = dialog?.querySelector('[data-queue-delete-form]');
        const title = dialog?.querySelector('[data-queue-delete-title]');
        const cancelButton = dialog?.querySelector('[data-queue-delete-cancel]');
        const openButtons = document.querySelectorAll('[data-queue-delete-open]');

        if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement)) return;

        openButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const deleteUrl = button.dataset.queueDeleteUrl ?? '';
                if (deleteUrl === '') return;

                form.action = deleteUrl;
                if (title) title.textContent = button.dataset.queueDeleteTitle ?? '';
                dialog.showModal();
            });
        });

        cancelButton?.addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', () => {
            form.removeAttribute('action');
            if (title) title.textContent = '';
        });
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('queue-management', initialize);
    } else {
        initialize();
    }
})();
