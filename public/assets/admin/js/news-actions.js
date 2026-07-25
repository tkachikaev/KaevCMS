(() => {
    'use strict';

    const initialize = () => {
        (() => {
            'use strict';

            const initializeDeleteDialog = () => {
                const dialog = document.querySelector('[data-news-delete-dialog]');
                const form = dialog?.querySelector('[data-news-delete-form]');
                const title = dialog?.querySelector('[data-news-delete-title]');
                const cancelButton = dialog?.querySelector('[data-news-delete-cancel]');
                const openButtons = document.querySelectorAll('[data-news-delete-open]');

                if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement) || openButtons.length === 0) {
                    return;
                }

                openButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const deleteUrl = button.dataset.newsDeleteUrl ?? '';
                        const newsTitle = button.dataset.newsDeleteTitle ?? '';

                        if (deleteUrl === '') {
                            return;
                        }

                        form.action = deleteUrl;
                        if (title) {
                            title.textContent = newsTitle;
                        }

                        dialog.showModal();
                    });
                });

                cancelButton?.addEventListener('click', () => dialog.close());

                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) {
                        dialog.close();
                    }
                });

                dialog.addEventListener('close', () => {
                    form.removeAttribute('action');
                    if (title) {
                        title.textContent = '';
                    }
                });
            };

            initializeDeleteDialog();
        })();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('news-actions', initialize);
    } else {
        initialize();
    }
})();
