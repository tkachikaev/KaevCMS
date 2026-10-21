(() => {
    'use strict';

    const initialize = () => {
        const cleanups = [];

        document.querySelectorAll('[data-copy-command]').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) return;

            const targetId = button.dataset.copyTarget;
            const target = targetId ? document.getElementById(targetId) : null;
            const container = button.closest('.update-agent-card, .update-incoming-card') ?? button.parentElement;
            const state = container?.querySelector('[data-copy-state]');
            if (!(target instanceof HTMLElement)) return;

            const copy = async () => {
                const value = target.textContent?.trim() ?? '';
                if (value === '') return;

                const copied = await window.KaevCMSAdminClipboard?.copy(value) === true;

                if (state instanceof HTMLElement) {
                    if (copied) {
                        delete state.dataset.type;
                        state.textContent = button.dataset.copySuccess || 'Command copied.';
                    } else {
                        state.textContent = button.dataset.copyError || 'Could not copy the command.';
                        state.dataset.type = 'error';
                    }
                }
            };

            button.addEventListener('click', copy);
            cleanups.push(() => button.removeEventListener('click', copy));
        });

        // Shared hosting often limits a request to 2-8 MB while a release package is larger.
        // Send the file in parts below that limit; the server assembles and verifies it.
        document.querySelectorAll('form[data-update-chunked-upload]').forEach((uploadForm) => {
            if (!(uploadForm instanceof HTMLFormElement) || typeof window.fetch !== 'function' || typeof Blob.prototype.slice !== 'function') return;

            const input = uploadForm.querySelector('input[type="file"]');
            const errorBox = uploadForm.querySelector('[data-update-upload-error]');
            const state = uploadForm.querySelector('[data-update-upload-state]');
            const buttons = [...uploadForm.querySelectorAll('button[type="submit"]')];
            const chunkBytes = Math.max(65536, Number(uploadForm.dataset.updateChunkBytes || 0));
            let busy = false;

            const showError = (text) => {
                if (errorBox instanceof HTMLElement) {
                    errorBox.querySelector('span').textContent = text;
                    errorBox.hidden = false;
                }
                if (state instanceof HTMLElement) state.textContent = '';
            };

            const randomId = () => [...window.crypto.getRandomValues(new Uint8Array(16))]
                .map((byte) => byte.toString(16).padStart(2, '0')).join('');

            const sendPart = async (body) => {
                let lastError = null;
                for (let attempt = 0; attempt < 3; attempt += 1) {
                    try {
                        const response = await fetch(uploadForm.dataset.updateChunkUrl, {
                            method: 'POST',
                            body,
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        const result = await response.json().catch(() => ({}));
                        if (response.ok) return result;
                        // The server refused the part: retrying cannot help.
                        throw Object.assign(new Error(result.message || uploadForm.dataset.updateInterrupted || ''), { final: true });
                    } catch (error) {
                        if (error.final) throw error;
                        lastError = error;
                        await new Promise((resolve) => window.setTimeout(resolve, 1000 * (attempt + 1)));
                    }
                }
                throw lastError;
            };

            const upload = async (event) => {
                const file = input instanceof HTMLInputElement ? input.files?.[0] : undefined;
                if (file === undefined || busy) return;

                event.preventDefault();
                busy = true;
                buttons.forEach((button) => { button.disabled = true; });
                if (errorBox instanceof HTMLElement) errorBox.hidden = true;

                const token = uploadForm.querySelector('input[name="_token"]')?.value || '';
                const total = Math.max(1, Math.ceil(file.size / chunkBytes));
                const uploadId = randomId();

                try {
                    for (let index = 0; index < total; index += 1) {
                        if (state instanceof HTMLElement) {
                            state.textContent = index === total - 1
                                ? (uploadForm.dataset.updateVerifying || '')
                                : (uploadForm.dataset.updateUploading || '').replace(':percent', String(Math.floor((index / total) * 100)));
                        }

                        const body = new FormData();
                        body.append('_token', token);
                        body.append('upload_id', uploadId);
                        body.append('index', String(index));
                        body.append('total', String(total));
                        body.append('size', String(file.size));
                        body.append('chunk', file.slice(index * chunkBytes, (index + 1) * chunkBytes), 'part.bin');

                        const result = await sendPart(body);
                        if (result.done && typeof result.redirect === 'string') {
                            window.location.assign(result.redirect);
                            return;
                        }
                    }
                    showError(uploadForm.dataset.updateInterrupted || '');
                } catch (error) {
                    showError((error && error.message) || uploadForm.dataset.updateInterrupted || '');
                }

                busy = false;
                buttons.forEach((button) => { button.disabled = false; });
            };

            uploadForm.addEventListener('submit', upload);
            cleanups.push(() => uploadForm.removeEventListener('submit', upload));
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
