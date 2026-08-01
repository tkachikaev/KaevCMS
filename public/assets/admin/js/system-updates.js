(() => {
    'use strict';

    const initialize = () => {
        const cleanups = [];

        document.querySelectorAll('[data-copy-command]').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) return;

            const targetId = button.dataset.copyTarget;
            const target = targetId ? document.getElementById(targetId) : null;
            const container = button.closest('.update-agent-card') ?? button.parentElement;
            const state = container?.querySelector('[data-copy-state]');
            if (!(target instanceof HTMLElement)) return;

            const copy = async () => {
                const value = target.textContent?.trim() ?? '';
                if (value === '') return;

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(value);
                    } else {
                        const selection = window.getSelection();
                        const range = document.createRange();
                        range.selectNodeContents(target);
                        selection?.removeAllRanges();
                        selection?.addRange(range);
                        const copied = document.execCommand('copy');
                        selection?.removeAllRanges();
                        if (!copied) throw new Error('Copy failed.');
                    }

                    if (state instanceof HTMLElement) {
                        delete state.dataset.type;
                        state.textContent = button.dataset.copySuccess || 'Command copied.';
                    }
                } catch {
                    if (state instanceof HTMLElement) {
                        state.textContent = button.dataset.copyError || 'Could not copy the command.';
                        state.dataset.type = 'error';
                    }
                }
            };

            button.addEventListener('click', copy);
            cleanups.push(() => button.removeEventListener('click', copy));
        });

        const pollingPanel = document.querySelector('[data-vds-agent-poll]');
        if (pollingPanel instanceof HTMLElement) {
            let active = true;
            const timeoutId = window.setTimeout(() => {
                if (active) window.location.reload();
            }, 3000);

            cleanups.push(() => {
                active = false;
                window.clearTimeout(timeoutId);
            });
        }

        return () => cleanups.forEach((cleanup) => cleanup());
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('system-updates', initialize);
    } else {
        initialize();
    }
})();
