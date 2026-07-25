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

            const fallbackCopy = () => {
                source.hidden = false;
                source.focus();
                source.select();

                const copied = document.execCommand('copy');
                source.hidden = true;

                if (!copied) throw new Error('Copy command failed.');
            };

            button.addEventListener('click', async () => {
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(source.value);
                    } else {
                        fallbackCopy();
                    }

                    setState(button.dataset.copySuccess || 'Report copied.');
                } catch {
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
