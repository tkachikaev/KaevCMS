(() => {
    'use strict';

    const initialize = () => {
        (() => {
            'use strict';

            const dialog = document.querySelector('[data-security-cleanup-dialog]');
            const openButton = document.querySelector('[data-security-cleanup-open]');
            const cancelButton = dialog?.querySelector('[data-security-cleanup-cancel]');
            const password = dialog?.querySelector('input[name="current_password"]');

            if (!(dialog instanceof HTMLDialogElement)) {
                return;
            }

            const openDialog = () => {
                dialog.showModal();
                window.setTimeout(() => password?.focus(), 0);
            };

            openButton?.addEventListener('click', openDialog);
            cancelButton?.addEventListener('click', () => dialog.close());

            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) {
                    dialog.close();
                }
            });

            dialog.addEventListener('close', () => {
                if (password instanceof HTMLInputElement) {
                    password.value = '';
                }
            });

            if (dialog.dataset.openOnError === '1') {
                openDialog();
            }
        })();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('security', initialize);
    } else {
        initialize();
    }
})();
