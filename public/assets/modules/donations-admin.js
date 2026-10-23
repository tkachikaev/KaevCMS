(() => {
    'use strict';
    const initialize = () => {
        const form = document.querySelector('[data-donations-workspace]');
        if (!(form instanceof HTMLFormElement)) return;
        const list = form.querySelector('[data-donations-rates]');
        const selector = form.querySelector('[data-donations-currency]');
        const template = form.querySelector('[data-donations-rate-template]');
        const status = form.querySelector('[data-donations-save-status]');
        const rows = () => Array.from(list.querySelectorAll('[data-donations-row]'));
        const dirty = () => {
            if (status) {
                status.textContent = status.dataset.dirty;
                status.hidden = false;
            }
        };
        const refresh = () => {
            const empty = form.querySelector('[data-donations-empty]');
            empty.hidden = rows().some((row) => row.querySelector('[data-donations-remove]').value !== '1');
            if (selector) Array.from(selector.options).forEach((option) => {
                option.disabled = option.value !== '' && rows().some((row) => row.dataset.code === option.value);
            });
            const add = form.querySelector('[data-donations-add]');
            if (add) add.disabled = !selector?.value;
        };
        const input = (event) => {
            if (event.target !== selector) dirty();
            refresh();
        };
        const click = (event) => {
            if (!(event.target instanceof Element)) return;
            if (event.target.closest('[data-donations-add]')) {
                const option = selector?.selectedOptions[0];
                if (!option || !/^[A-Z]{3}$/.test(option.value) || option.disabled || !(template instanceof HTMLTemplateElement)) return;
                const fragment = template.content.cloneNode(true);
                const row = fragment.querySelector('[data-donations-row]');
                row.dataset.code = option.value;
                row.dataset.exponent = option.dataset.exponent;
                row.dataset.testid = `donations-rate-${option.value}`;
                row.querySelectorAll('*').forEach((element) => {
                    ['name', 'id', 'for', 'aria-labelledby'].forEach((attribute) => {
                        if (element.hasAttribute(attribute)) element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__CODE__', option.value));
                    });
                });
                row.querySelector('[data-currency-code]').textContent = option.value;
                row.querySelector('[data-removed-code]').textContent = option.value;
                row.querySelector('[data-currency-name]').textContent = option.dataset.name;
                list.append(fragment);
                selector.value = '';
                row.querySelector('[data-donations-price]').focus();
                dirty(); refresh();
                return;
            }
            const remove = event.target.closest('[data-donations-delete]');
            const undo = event.target.closest('[data-donations-undo]');
            const row = (remove || undo)?.closest('[data-donations-row]');
            if (!row) return;
            if (remove && row.dataset.existing === '0') {
                row.remove();
            } else {
                const removed = Boolean(remove);
                row.querySelector('[data-donations-remove]').value = removed ? '1' : '0';
                row.querySelector('[data-donations-fields]').disabled = removed;
                row.querySelector('[data-donations-fields]').hidden = removed;
                row.querySelector('[data-donations-removal]').hidden = !removed;
                row.classList.toggle('is-removed', removed);
            }
            dirty(); refresh();
        };
        form.addEventListener('input', input);
        form.addEventListener('change', input);
        form.addEventListener('click', click);
        refresh();
        return () => {
            form.removeEventListener('input', input);
            form.removeEventListener('change', input);
            form.removeEventListener('click', click);
        };
    };
    const initializeActivation = () => {
        const form = document.querySelector('[data-donations-activation]');
        if (!(form instanceof HTMLFormElement)) return;
        const mode = form.querySelector('#donations-payment-mode');
        const controls = form.querySelector('[data-donations-pilot-controls]');
        const player = form.querySelector('#donations-pilot-player');
        const refresh = () => {
            controls.hidden = mode.value !== 'pilot';
            player.required = mode.value === 'pilot';
            form.querySelectorAll('[data-donations-mode-help]').forEach((help) => {
                help.hidden = help.dataset.donationsModeHelp !== mode.value;
            });
        };
        mode.addEventListener('change', refresh);
        refresh();
        return () => mode.removeEventListener('change', refresh);
    };
    // "Test" for YooMoney notifications: wait until the owner presses "Test" on YooMoney's
    // side, show the outcome, and on success save the settings form as its own button does.
    const initializeNotificationTest = () => {
        const open = document.querySelector('[data-yoomoney-test-open]');
        const dialog = document.querySelector('[data-yoomoney-test-dialog]');
        const settings = document.querySelector('[data-testid="donations-yoomoney-simple-settings"]');
        if (!(open instanceof HTMLButtonElement) || !(dialog instanceof HTMLDialogElement)) return;
        const retry = dialog.querySelector('[data-yoomoney-test-retry]');
        let timer = null;
        let run = 0;

        const show = (state, message) => {
            dialog.dataset.state = state;
            dialog.querySelectorAll('[data-test-copy]').forEach((copy) => {
                copy.hidden = copy.dataset.testCopy !== state;
            });
            if (message) dialog.querySelector('[data-test-error]').textContent = message;
            retry.hidden = !['signature_mismatch', 'expired', 'error'].includes(state);
        };
        const stop = () => {
            window.clearTimeout(timer);
            run += 1;
        };
        const poll = (token, current) => {
            timer = window.setTimeout(async () => {
                if (current !== run) return;
                try {
                    const response = await fetch(open.dataset.statusUrl.replace(/0{40}$/, token), {
                        headers: { Accept: 'application/json' }, credentials: 'same-origin',
                    });
                    const body = await response.json();
                    if (current !== run) return;
                    if (body.status === 'waiting') {
                        poll(token, current);
                        return;
                    }
                    show(body.status);
                    if (body.status === 'received' && settings instanceof HTMLFormElement) {
                        window.setTimeout(() => settings.requestSubmit(), 1200);
                    }
                } catch {
                    if (current === run) poll(token, current);
                }
            }, 2000);
        };
        const start = async () => {
            stop();
            const current = run;
            show('waiting');
            if (!dialog.open) dialog.showModal();
            const secret = settings?.querySelector('[name="credentials[notification_secret]"]')?.value || '';
            try {
                const response = await fetch(open.dataset.startUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ secret }),
                });
                const body = await response.json();
                if (current !== run) return;
                if (!response.ok || typeof body.token !== 'string') {
                    show('error', body.error);
                    return;
                }
                poll(body.token, current);
            } catch {
                if (current === run) show('error');
            }
        };
        const close = () => {
            stop();
            if (dialog.open) dialog.close();
        };
        const onClick = (event) => {
            if (event.target instanceof Element && event.target.closest('[data-yoomoney-test-close]')) close();
        };
        open.addEventListener('click', start);
        retry.addEventListener('click', start);
        dialog.addEventListener('click', onClick);
        dialog.addEventListener('close', stop);
        return () => {
            close();
            open.removeEventListener('click', start);
            retry.removeEventListener('click', start);
            dialog.removeEventListener('click', onClick);
            dialog.removeEventListener('close', stop);
        };
    };
    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('donations', initialize);
        window.KaevCMSAdmin.registerPage('donations-activation', initializeActivation);
        window.KaevCMSAdmin.registerPage('donations-yoomoney-test', initializeNotificationTest);
    } else {
        initialize();
        initializeActivation();
        initializeNotificationTest();
    }
})();
