(() => {
    'use strict';

    const initialize = () => {
        const button = document.querySelector('[data-copy-system-report]');
        const source = document.querySelector('[data-system-report]');
        const state = document.querySelector('[data-system-copy-state]');

        if (button instanceof HTMLButtonElement && source instanceof HTMLTextAreaElement) {
            const setState = (message, type = 'success') => {
                if (!(state instanceof HTMLElement)) return;

                state.textContent = message;
                state.dataset.type = type;
            };

            button.addEventListener('click', async () => {
                const copied = await window.KaevCMSAdminClipboard?.copy(source.value) === true;

                if (copied) {
                    setState(button.dataset.copySuccess || 'Report copied.');
                } else {
                    setState(button.dataset.copyError || 'Could not copy the report.', 'error');
                }
            });
        }

    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('system', initialize);
    } else {
        initialize();
    }
})();
