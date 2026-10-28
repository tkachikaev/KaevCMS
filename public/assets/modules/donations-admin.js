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

        const stillWaiting = dialog.querySelector('[data-test-still-waiting]');
        const show = (state, message, waiting = false) => {
            dialog.dataset.state = state;
            dialog.querySelectorAll('[data-test-copy]').forEach((copy) => {
                copy.hidden = copy.dataset.testCopy !== state;
            });
            if (message) dialog.querySelector('[data-test-error]').textContent = message;
            if (stillWaiting) stillWaiting.hidden = !waiting;
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
                    // A mismatching body may be forged; YooMoney's signed test can still follow.
                    if (body.waiting === true) {
                        if (body.status !== 'waiting') show(body.status, undefined, true);
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
    const initializeProviderTests = () => {
        const cleanups = [];
        document.querySelectorAll('[data-provider-test-dialog]').forEach(dialog => {
            if (!(dialog instanceof HTMLDialogElement)) return;
            const provider = dialog.dataset.providerTestDialog;
            const open = document.querySelector(`[data-provider-test-open="${provider}"]`);
            const close = dialog.querySelector('[data-provider-test-close]');
            if (!(open instanceof HTMLButtonElement) || !(close instanceof HTMLButtonElement)) return;
            const key = `kaevcms.provider-test.${provider}`;
            const remember = value => { try { if (value) sessionStorage.setItem(key, 'open'); else sessionStorage.removeItem(key); } catch {} };
            const show = () => { if (!dialog.open) dialog.showModal(); remember(true); };
            const hide = () => { if (dialog.open) dialog.close(); };
            const closed = () => remember(false);
            open.addEventListener('click', show);
            close.addEventListener('click', hide);
            dialog.addEventListener('close', closed);
            open.disabled = false;
            try {
                if (sessionStorage.getItem(key) === 'open' && !document.querySelector('[data-testid="freekassa-test-continue"]')) show();
            } catch {}
            cleanups.push(() => {
                open.disabled = true;
                open.removeEventListener('click', show);
                close.removeEventListener('click', hide);
                dialog.removeEventListener('close', closed);
                hide();
                remember(false);
            });
        });
        return () => cleanups.forEach(cleanup => cleanup());
    };
    const initializeFreeKassaResult = () => {
        const box = document.querySelector('[data-freekassa-result]');
        if (!(box instanceof HTMLElement)) return;
        const form = document.querySelector('[data-testid="freekassa-test-purchase-form"]');
        let timer, controller, requestTimeout, disposed = false, busy = false, awaitingNew = false, submittedAt = 0;
        const show = (state, summary = '') => {
            box.dataset.state = state;
            box.querySelectorAll('[data-freekassa-state]').forEach(item => { item.hidden = item.dataset.freekassaState !== state; });
            box.querySelector('[data-freekassa-summary]').textContent = summary;
        };
        const poll = async () => {
            clearTimeout(timer);
            if (disposed || busy || document.hidden) return;
            busy = true;
            controller = new AbortController();
            requestTimeout = setTimeout(() => controller.abort(), 10000);
            try {
                const url = new URL(box.dataset.statusUrl, location.origin);
                if (url.origin !== location.origin) throw new Error('Unexpected status origin');
                url.searchParams.set('test_status', '1');
                const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
                if (!response.ok) throw new Error('Status unavailable');
                const result = await response.json();
                if (disposed) return;
                if (!['none', 'pending', 'verified', 'expired'].includes(result.state)) throw new Error('Invalid status');
                if (awaitingNew && (result.reference || '') === box.dataset.reference) {
                    if (Date.now() - submittedAt > 30000) { awaitingNew = false; show('error'); }
                } else {
                    awaitingNew = false;
                    box.dataset.reference = result.reference || '';
                    show(result.state, result.summary || '');
                }
            } catch (error) {
                if (!disposed) { awaitingNew = false; show('error'); }
            } finally {
                clearTimeout(requestTimeout);
                busy = false;
                if (!disposed && (awaitingNew || ['pending', 'error'].includes(box.dataset.state))) timer = setTimeout(poll, 3000);
            }
        };
        const start = () => { clearTimeout(timer); awaitingNew = true; submittedAt = Date.now(); show('pending'); timer = setTimeout(poll, 1500); };
        const focus = () => { if (!document.hidden) poll(); };
        form?.addEventListener('submit', start);
        window.addEventListener('focus', focus);
        document.addEventListener('visibilitychange', focus);
        poll();
        return () => {
            disposed = true;
            clearTimeout(timer);
            clearTimeout(requestTimeout);
            controller?.abort();
            form?.removeEventListener('submit', start);
            window.removeEventListener('focus', focus);
            document.removeEventListener('visibilitychange', focus);
        };
    };
    const initializeCopyFields = () => {
        const fields = Array.from(document.querySelectorAll('[data-donations-select-copy]'));
        const select = (event) => {
            if (event.currentTarget instanceof HTMLInputElement) event.currentTarget.select();
        };
        fields.forEach(field => field.addEventListener('click', select));
        return () => fields.forEach(field => field.removeEventListener('click', select));
    };
    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('donations', initialize);
        window.KaevCMSAdmin.registerPage('donations-activation', initializeActivation);
        window.KaevCMSAdmin.registerPage('donations-yoomoney-test', initializeNotificationTest);
        window.KaevCMSAdmin.registerPage('donations-copy-fields', initializeCopyFields);
        window.KaevCMSAdmin.registerPage('donations-freekassa-result', initializeFreeKassaResult);
        window.KaevCMSAdmin.registerPage('donations-provider-tests', initializeProviderTests);
    } else {
        initialize();
        initializeActivation();
        initializeNotificationTest();
        initializeCopyFields();
        initializeFreeKassaResult();
        initializeProviderTests();
    }
})();
