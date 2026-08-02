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

        const dialog = document.querySelector('[data-update-progress-dialog]');
        const form = document.querySelector('[data-update-progress-form]');

        if (dialog instanceof HTMLDialogElement) {
            const message = dialog.querySelector('[data-update-progress-message]');
            const steps = [...dialog.querySelectorAll('[data-update-step]')];
            const statusUrl = dialog.dataset.updateStatusUrl;
            const controller = new AbortController();
            let timeoutId = null;
            let active = true;

            const setStep = (step) => {
                const order = ['verify', 'queue', 'install', 'finalize'];
                const current = Math.max(0, order.indexOf(step));

                steps.forEach((item) => {
                    const index = order.indexOf(item.dataset.updateStep || '');
                    item.classList.toggle('is-current', index === current);
                    item.classList.toggle('is-complete', index >= 0 && index < current);
                });
            };

            const open = () => {
                if (!dialog.open) dialog.showModal();
                document.documentElement.classList.add('admin-modal-open');
            };

            const phaseStep = (result) => {
                if (result.queued) return 'queue';
                if (result.phase === 'preparing') return 'queue';
                if (result.phase === 'files' || result.phase === 'migrations') return 'install';
                if (result.phase === 'finalizing' || result.phase === 'completed') return 'finalize';
                return 'verify';
            };

            const poll = async () => {
                if (!active || !statusUrl) return;

                try {
                    const response = await fetch(statusUrl, {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                        credentials: 'same-origin',
                        signal: controller.signal,
                    });

                    if (response.ok) {
                        const result = await response.json();
                        if (message instanceof HTMLElement && typeof result.message === 'string') {
                            message.textContent = result.message;
                        }
                        setStep(phaseStep(result));

                        if (result.completed) {
                            timeoutId = window.setTimeout(() => window.location.assign(result.details_url), 700);
                            return;
                        }
                    }
                } catch (error) {
                    if (error instanceof DOMException && error.name === 'AbortError') return;
                }

                timeoutId = window.setTimeout(poll, 3000);
            };

            const submit = (event) => {
                if (!(form instanceof HTMLFormElement) || form.dataset.updateSubmitting === '1') return;

                event.preventDefault();
                form.dataset.updateSubmitting = '1';
                open();
                setStep('verify');
                if (message instanceof HTMLElement) {
                    message.textContent = form.dataset.updateProgressMode === 'agent'
                        ? (form.dataset.updateProgressAgentMessage || '')
                        : (form.dataset.updateProgressWebMessage || '');
                }

                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((control) => {
                    if (control instanceof HTMLButtonElement || control instanceof HTMLInputElement) {
                        control.disabled = true;
                    }
                });

                window.requestAnimationFrame(() => {
                    window.setTimeout(() => HTMLFormElement.prototype.submit.call(form), 0);
                });
            };

            if (form instanceof HTMLFormElement) {
                form.addEventListener('submit', submit);
                cleanups.push(() => form.removeEventListener('submit', submit));
            }

            dialog.addEventListener('cancel', (event) => event.preventDefault());

            if (dialog.dataset.updateAutoOpen === '1') {
                open();
                setStep(dialog.dataset.updateCurrentStatus === 'queued' ? 'queue' : 'install');
                timeoutId = window.setTimeout(poll, 500);
            }

            cleanups.push(() => {
                active = false;
                controller.abort();
                if (timeoutId !== null) window.clearTimeout(timeoutId);
                document.documentElement.classList.remove('admin-modal-open');
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
