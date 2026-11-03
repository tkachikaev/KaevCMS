(() => {
    'use strict';

    // Delegated once for every account theme; survives Livewire navigation.
    if (document.documentElement.dataset.shopStorefrontInstalled === '1') {
        return;
    }
    document.documentElement.dataset.shopStorefrontInstalled = '1';
    document.documentElement.dataset.shopHoldReady = '1';

    const HOLD_MS = 1200;
    const storefront = () => document.querySelector('[data-shop-storefront]');

    /* Server and section switches replace only the catalog block. ------ */

    let loading = null;
    const load = async (url, push) => {
        const current = storefront();
        if (!current) return false;
        loading?.abort();
        loading = new AbortController();
        current.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
                signal: loading.signal,
            });
            const next = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('[data-shop-storefront]');
            if (!response.ok || !next) throw new Error('swap_failed');
            current.replaceWith(document.adoptNode(next));
            if (push) history.pushState({ shopStorefront: true }, '', response.url);
            return true;
        } catch (error) {
            if (error?.name === 'AbortError') return true;
            window.location.assign(url);
            return false;
        } finally {
            storefront()?.removeAttribute('aria-busy');
        }
    };

    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('[data-shop-storefront] a[data-shop-switch]') : null;
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (link.getAttribute('aria-current') === 'page') return;
        load(link.href, true);
    });
    window.addEventListener('popstate', (event) => {
        if (event.state?.shopStorefront && storefront()) load(window.location.href, false);
    });

    /* Press and hold to buy: the button and the item icons fill up. ---- */

    let hold = null;
    const cancel = () => {
        if (!hold || hold.done) return;
        window.clearTimeout(hold.timer);
        hold.dialog.removeAttribute('data-shop-holding');
        hold = null;
    };
    const start = (button) => {
        const dialog = button.closest('dialog');
        const form = button.form;
        if (!dialog || !form || button.disabled || hold) return;
        dialog.style.setProperty('--shop-hold-ms', `${HOLD_MS}ms`);
        // Restart the fill animation from the left on every new press.
        dialog.removeAttribute('data-shop-holding');
        void dialog.offsetWidth;
        dialog.setAttribute('data-shop-holding', '');
        hold = {
            dialog,
            done: false,
            timer: window.setTimeout(() => {
                hold.done = true;
                dialog.setAttribute('data-shop-held', '');
                button.disabled = true;
                form.submit();
            }, HOLD_MS),
        };
    };

    document.addEventListener('pointerdown', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-shop-hold]') : null;
        if (!button || event.button !== 0) return;
        event.preventDefault();
        start(button);
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((type) => {
        document.addEventListener(type, (event) => {
            // Leaving the label inside the button is not leaving the button.
            if (type !== 'pointerleave' || (event.target instanceof Element && event.target.matches('[data-shop-hold]'))) cancel();
        }, true);
    });
    document.addEventListener('keydown', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-shop-hold]') : null;
        if (!button || (event.key !== 'Enter' && event.key !== ' ')) return;
        event.preventDefault();
        if (!event.repeat) start(button);
    });
    document.addEventListener('keyup', (event) => {
        if (event.key === 'Enter' || event.key === ' ') cancel();
    });
    // A plain click (or Enter without holding) never buys on its own.
    document.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-shop-hold]')) event.preventDefault();
    }, true);
    document.addEventListener('close', (event) => {
        if (event.target instanceof HTMLDialogElement && hold?.dialog === event.target) cancel();
    }, true);
})();
