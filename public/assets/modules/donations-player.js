(() => {
    'use strict';
    const initialized = new WeakMap();
    const format = (minor, exponent) => {
        const factor = 10n ** BigInt(exponent);
        return `${minor / factor}${exponent ? `.${String(minor % factor).padStart(exponent, '0')}` : ''}`;
    };
    const initializeCalculator = root => {
        const form = root.querySelector('[data-testid="donations-quote-form"]');
        if (!form || initialized.has(root) || typeof BigInt !== 'function') return;
        const coins = form.elements.namedItem('coins');
        const currency = form.elements.namedItem('currency');
        const slider = form.querySelector('[type="range"]');
        const result = root.querySelector('[data-testid="donations-quote-result"]');
        const error = root.querySelector('[data-testid="donations-quote-error"]');
        const total = result.querySelector('[data-donations-total]');
        const receive = result.querySelector('[data-donations-receive]');
        const breakdown = result.querySelector('[data-donations-breakdown]');
        const order = root.querySelector('[data-testid="donations-create-order"]');
        const orderButton = order?.querySelector('[type="submit"]');
        const submitError = order?.querySelector('[data-donations-submit-error]');
        const providers = [...(order?.querySelectorAll('[data-donations-provider]') ?? [])];
        const label = orderButton?.textContent;
        let busy = false;
        let quotedTotal = '';
        let submittedSelection = '';
        let submission = 0;
        let activeRequest;
        const refresh = () => {
            const option = currency.selectedOptions[0];
            const count = coins.value;
            const validCoins = /^[1-9][0-9]{0,6}$/.test(count) && coins.checkValidity();
            const exponent = Number(option?.dataset.exponent);
            const price = option?.dataset.priceMinor ?? '';
            const validCurrency = /^[A-Z]{3}$/.test(option?.value ?? '') && /^[1-9][0-9]{0,8}$/.test(price)
                && Number.isInteger(exponent) && exponent >= 0 && exponent <= 4;
            const valid = validCoins && validCurrency;
            coins.setAttribute('aria-invalid', String(!validCoins));
            error.hidden = valid;
            error.textContent = valid ? '' : (validCoins ? error.dataset.invalidCurrency : error.dataset.invalidCoins);
            for (const row of providers) {
                row.hidden = !row.dataset.currencies.split(',').includes(option?.value);
                const input = row.querySelector('input');
                input.disabled = row.hidden || busy;
                if (row.hidden) input.checked = false;
            }
            if (providers.length && !providers.some(row => !row.hidden && row.querySelector('input').checked)) {
                const first = providers.find(row => !row.hidden);
                if (first) first.querySelector('input').checked = true;
            }
            if (order) {
                orderButton.disabled = busy || !valid || !providers.some(row => !row.hidden && row.querySelector('input').checked);
                order.elements.namedItem('coins').value = valid ? count : '';
                order.elements.namedItem('currency').value = valid ? option.value : '';
            }
            result.hidden = false;
            quotedTotal = '';
            if (!valid) {
                total.textContent = '—';
                receive.textContent = '—';
                breakdown.textContent = '';
                return false;
            }
            slider.value = count;
            const span = Number(slider.max) - Number(slider.min);
            slider.style.setProperty('--donations-progress', `${span ? (Number(count) - Number(slider.min)) / span * 100 : 100}%`);
            quotedTotal = String(BigInt(price) * BigInt(count));
            total.textContent = `${format(BigInt(quotedTotal), exponent)} ${option.value}`;
            const amount = Number(count);
            const russian = document.documentElement.lang.startsWith('ru');
            const plural = russian ? (amount % 100 >= 11 && amount % 100 <= 14 ? 'many' : (amount % 10 === 1 ? 'one' : (amount % 10 >= 2 && amount % 10 <= 4 ? 'few' : 'many'))) : (amount === 1 ? 'one' : 'many');
            const coinName = receive.dataset[plural] || (russian ? { one: 'монета', few: 'монеты', many: 'монет' }[plural] : (amount === 1 ? 'coin' : 'coins'));
            receive.textContent = `${new Intl.NumberFormat(document.documentElement.lang).format(amount)} ${coinName}`;
            breakdown.textContent = breakdown.dataset.template.replace(/:coins|:price|:currency/g, key => ({
                ':coins': count, ':price': format(BigInt(price), exponent), ':currency': option.value,
            })[key]);
            return true;
        };
        const setBusy = value => {
            busy = value;
            coins.disabled = value;
            currency.disabled = value;
            slider.disabled = value;
            if (order) {
                order.setAttribute('aria-busy', String(value));
                orderButton.textContent = value ? order.dataset.pending : label;
            }
            refresh();
        };
        const input = event => {
            if (event.target === slider) coins.value = slider.value;
            if (submitError) submitError.hidden = true;
            refresh();
        };
        form.addEventListener('input', input);
        form.addEventListener('change', input);
        form.addEventListener('submit', event => { event.preventDefault(); refresh(); });
        order?.addEventListener('change', refresh);
        order?.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy) return;
            if (!refresh()) { coins.focus(); return; }
            if (orderButton.disabled) return;
            const selection = `${coins.value}|${currency.value}|${order.elements.namedItem('provider').value}`;
            if (submittedSelection && submittedSelection !== selection) {
                order.elements.namedItem('request_key').value = crypto.randomUUID();
            }
            submittedSelection = selection;
            const data = new FormData(order);
            data.set('quoted_total_minor', quotedTotal);
            submitError.hidden = true;
            setBusy(true);
            const currentSubmission = ++submission;
            const controller = new AbortController();
            activeRequest = controller;
            const timeout = setTimeout(() => controller.abort(), 20000);
            let redirecting = false;
            try {
                const response = await fetch(order.dataset.topupUrl, {
                    method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store',
                    signal: controller.signal,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error(order.dataset.failed);
                const payload = await response.json();
                if (!root.isConnected || currentSubmission !== submission) return;
                if (response.status === 409 && payload.quote) {
                    const option = currency.selectedOptions[0];
                    if (payload.quote.currency_code === option.value) {
                        option.dataset.priceMinor = String(payload.quote.unit_price_minor);
                        option.dataset.exponent = String(payload.quote.exponent);
                    }
                    throw new Error(payload.message);
                }
                if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] || payload.message || order.dataset.failed);
                // Only reviewed provider adapters may define an external form target.
                if (payload.action !== 'https://yoomoney.ru/quickpay/confirm' || payload.method !== 'POST'
                    || payload.fields?.sum !== format(BigInt(quotedTotal), Number(currency.selectedOptions[0].dataset.exponent))) {
                    throw new Error(order.dataset.failed);
                }
                const external = document.createElement('form');
                external.method = 'POST';
                external.action = payload.action;
                external.hidden = true;
                for (const name of ['receiver', 'quickpay-form', 'label', 'sum', 'successURL', 'paymentType']) {
                    if (typeof payload.fields[name] !== 'string') throw new Error(order.dataset.failed);
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = name; input.value = payload.fields[name];
                    external.append(input);
                }
                document.body.append(external);
                redirecting = true;
                HTMLFormElement.prototype.submit.call(external);
            } catch (exception) {
                if (currentSubmission === submission) {
                    submitError.textContent = exception instanceof Error && !(exception instanceof TypeError) && exception.name !== 'AbortError' ? exception.message : order.dataset.failed;
                    submitError.hidden = false;
                }
            } finally {
                clearTimeout(timeout);
                if (!redirecting && currentSubmission === submission) setBusy(false);
            }
        });
        const reset = () => { submission++; activeRequest?.abort(); setBusy(false); };
        initialized.set(root, reset);
        root.closest('dialog')?.addEventListener('close', reset);
        refresh();
        form.querySelector('[data-donations-range-control]').hidden = false;
        form.querySelector('[data-donations-live-help]').hidden = false;
        form.querySelector('#donations-player-limits').hidden = true;
        form.querySelector('[data-donations-calculate-fallback]').hidden = true;
    };
    let loading;
    let opener;
    let backdropPress = false;
    const dialogForLink = link => {
        if (!link || link.hasAttribute('data-donations-fallback')) return null;
        const url = new URL(link.href, location.href);
        return Array.from(document.querySelectorAll('[data-donations-dialog]')).find(dialog => {
            const endpoint = new URL(dialog.dataset.url, location.href);
            return url.origin === endpoint.origin && url.pathname === endpoint.pathname;
        });
    };
    const outsideDialog = (event, dialog) => {
        const bounds = dialog.getBoundingClientRect();
        return event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom;
    };
    document.addEventListener('pointerdown', event => {
        const dialog = event.target.closest('[data-donations-dialog]');
        backdropPress = dialog?.open && event.target === dialog && outsideDialog(event, dialog);
    }, true);
    const initialize = () => {
        document.querySelectorAll('[data-donations-calculator]').forEach(initializeCalculator);
        document.querySelectorAll('[data-donations-dialog]').forEach(dialog => {
            if (dialog.dataset.initialized) return;
            dialog.dataset.initialized = 'true';
            dialog.addEventListener('close', () => { loading?.abort(); if (opener?.isConnected) opener.focus(); });
        });
    };
    // Livewire starts link navigation on mousedown / Enter, before the click.
    // Keep the normal href as a no-JS fallback, but reserve these links for the dialog.
    const reserveDialogLink = event => {
        if (event.type === 'keydown' && event.key !== 'Enter') return;
        if (event.type === 'mousedown' && event.button !== 0) return;
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (link?.target !== '_blank' && dialogForLink(link)) event.stopImmediatePropagation();
    };
    document.addEventListener('mousedown', reserveDialogLink, true);
    document.addEventListener('keydown', reserveDialogLink, true);
    document.addEventListener('click', async event => {
        const activeDialog = event.target.closest('[data-donations-dialog]');
        if (event.target.closest('[data-donations-close]')) { activeDialog?.close(); return; }
        if (activeDialog && event.target === activeDialog) {
            if (backdropPress && outsideDialog(event, activeDialog)) activeDialog.close();
            backdropPress = false;
            return;
        }
        const link = event.target.closest('a[href]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank') return;
        const dialog = dialogForLink(link);
        if (!dialog) return;
        const url = new URL(link.href, location.href);
        event.preventDefault();
        event.stopImmediatePropagation();
        opener = link.closest('details')?.querySelector('summary') || link;
        document.querySelectorAll('.account-profile-menu[open]').forEach(menu => menu.removeAttribute('open'));
        document.documentElement.classList.remove('account-sidebar-open');
        document.querySelector('[data-account-sidebar-toggle]')?.setAttribute('aria-expanded', 'false');
        if (!dialog.open) dialog.showModal();
        const content = dialog.querySelector('[data-donations-dialog-content]');
        if (content.childElementCount && dialog.dataset.kind !== 'exchange') { content.querySelector('input')?.focus(); return; }
        content.replaceChildren();
        loading?.abort();
        const controller = new AbortController();
        loading = controller;
        const progress = dialog.querySelector('[data-donations-loading]');
        const failed = dialog.querySelector('[data-donations-load-error]');
        progress.hidden = false; failed.hidden = true;
        content.setAttribute('aria-busy', 'true');
        try {
            url.searchParams.set('dialog', '1');
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
            if (!response.ok) throw new Error('Unavailable');
            const markup = new DOMParser().parseFromString(await response.text(), 'text/html');
            const calculator = markup.querySelector(dialog.dataset.kind === 'exchange' ? '[data-exchange-content]' : '[data-donations-calculator]');
            if (!calculator) throw new Error('Unavailable');
            if (!dialog.open || !dialog.isConnected) return;
            content.replaceChildren(document.importNode(calculator, true));
            if (dialog.dataset.kind === 'exchange') document.dispatchEvent(new Event('donations:content-loaded'));
            else initializeCalculator(content.firstElementChild);
            content.querySelector('select, input:not([type="hidden"])')?.focus();
        } catch (exception) {
            if (exception.name !== 'AbortError') failed.hidden = false;
        } finally {
            if (loading === controller) {
                progress.hidden = true;
                content.removeAttribute('aria-busy');
            }
        }
    }, true);
    document.addEventListener('livewire:navigating', () => {
        loading?.abort();
        document.querySelectorAll('[data-donations-dialog][open]').forEach(dialog => dialog.close());
    });
    document.addEventListener('livewire:navigated', initialize);
    window.addEventListener('pageshow', () => document.querySelectorAll('[data-donations-calculator]').forEach(root => initialized.get(root)?.()));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
