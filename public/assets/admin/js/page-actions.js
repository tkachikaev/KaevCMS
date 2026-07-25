(() => {
    'use strict';

    const initialize = () => {
        (() => {
            'use strict';

            const initializeDeleteDialog = () => {
                const dialog = document.querySelector('[data-page-delete-dialog]');
                const form = dialog?.querySelector('[data-page-delete-form]');
                const title = dialog?.querySelector('[data-page-delete-title]');
                const cancelButton = dialog?.querySelector('[data-page-delete-cancel]');
                const openButtons = document.querySelectorAll('[data-page-delete-open]');

                if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement) || openButtons.length === 0) return;

                openButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const deleteUrl = button.dataset.pageDeleteUrl ?? '';
                        if (deleteUrl === '') return;

                        form.action = deleteUrl;
                        if (title) title.textContent = button.dataset.pageDeleteTitle ?? '';
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

            initializeDeleteDialog();
        })();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('page-actions', initialize);
    } else {
        initialize();
    }
})();
