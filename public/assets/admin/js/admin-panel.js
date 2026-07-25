(() => {
    'use strict';

    const initialize = () => {
        const form = document.querySelector('[data-admin-path-form]');
        const input = document.querySelector('[data-admin-path-suffix]');
        const dialog = document.querySelector('[data-admin-path-dialog]');
        const currentTarget = dialog?.querySelector('[data-admin-path-current]');
        const newTarget = dialog?.querySelector('[data-admin-path-new]');
        const cancelButton = dialog?.querySelector('[data-admin-path-cancel]');
        const confirmButton = dialog?.querySelector('[data-admin-path-confirm]');

        if (
            form instanceof HTMLFormElement
            && input instanceof HTMLInputElement
            && dialog instanceof HTMLDialogElement
            && confirmButton instanceof HTMLButtonElement
        ) {
            let confirmed = false;

            form.addEventListener('submit', (event) => {
                if (confirmed) return;

                const suffix = input.value.trim();
                const newPath = suffix === '' ? '/admin' : `/admin-${suffix}`;
                const currentPath = form.dataset.currentAdminPath || '/admin';

                if (newPath === currentPath) return;

                event.preventDefault();
                if (currentTarget) currentTarget.textContent = currentPath;
                if (newTarget) newTarget.textContent = newPath;
                dialog.showModal();
            });

            confirmButton.addEventListener('click', () => {
                confirmed = true;
                dialog.close();
                form.requestSubmit();
            });

            cancelButton?.addEventListener('click', () => dialog.close());
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close();
            });
        }
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('admin-panel', initialize);
    } else {
        initialize();
    }
})();
