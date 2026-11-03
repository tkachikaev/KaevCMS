(() => {
    'use strict';

    // Delegated once; survives Livewire navigation and in-place page updates.
    if (document.documentElement.dataset.referralsAdminInstalled === '1') return;
    document.documentElement.dataset.referralsAdminInstalled = '1';

    const page = () => document.querySelector('[data-referrals-page]');
    const text = (name) => page()?.dataset[name] ?? '';

    /* Settings forms post in the background and the page block is swapped. */

    let swapping = false;
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-referrals-async]') || !page()) return;
        if (swapping || form.dataset.referralsNative === '1') return;
        event.preventDefault();
        swapping = true;
        page()?.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form, event.submitter ?? undefined), credentials: 'same-origin',
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const next = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('[data-referrals-page]');
            const current = page();
            if (!response.ok || !next || !current) throw new Error('swap_failed');
            const scroll = window.scrollY;
            current.replaceWith(document.adoptNode(next));
            if (response.url !== window.location.href) history.replaceState(history.state, '', response.url);
            window.scrollTo({ top: scroll });
        } catch {
            form.dataset.referralsNative = '1';
            event.submitter ? form.requestSubmit(event.submitter) : form.requestSubmit();
        } finally {
            swapping = false;
            page()?.removeAttribute('aria-busy');
        }
    });

    /* Item preview next to the ID field, like promo-code rewards. ------ */

    const timers = new WeakMap();
    const fillPreview = (form, payload) => {
        const preview = form.querySelector('[data-referrals-item-preview]');
        const name = form.querySelector('[data-referrals-item-name]');
        if (preview) {
            preview.replaceChildren();
            if (payload?.icon_url) {
                const image = new Image(48, 48);
                image.src = payload.icon_url;
                image.alt = '';
                preview.append(image);
            }
        }
        if (name) name.textContent = payload ? payload.name : text('referralsTextEnterItem');
    };
    const lookup = async (form) => {
        const input = form.querySelector('[data-referrals-item-id]');
        const itemId = Number.parseInt(input?.value ?? '', 10);
        if (!Number.isInteger(itemId) || itemId < 1) {
            fillPreview(form, null);
            return;
        }
        try {
            const response = await fetch(text('referralsPreviewUrl').replace(/\/0$/, `/${itemId}`), {
                credentials: 'same-origin', headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('preview_failed');
            const payload = await response.json();
            if (Number.parseInt(input.value, 10) === itemId) fillPreview(form, payload);
        } catch {
            fillPreview(form, { name: text('referralsTextUnknownItem') });
        }
    };
    document.addEventListener('input', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.matches('[data-referrals-item-id]')) return;
        const form = input.form;
        if (!form) return;
        window.clearTimeout(timers.get(form));
        timers.set(form, window.setTimeout(() => lookup(form), 250));
    });

    /* Rules are added and removed in place. ---------------------------- */

    const emptyMessage = (list) => {
        if (list.querySelector('[data-referral-rule-id]') || list.querySelector('[data-referrals-empty]')) return;
        const empty = document.createElement('p');
        empty.className = 'referrals-empty';
        empty.dataset.referralsEmpty = '';
        empty.textContent = list.dataset.emptyText || '';
        list.append(empty);
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-referrals-add-rule]')) return;
        event.preventDefault();
        if (form.dataset.saving === 'true') return;
        const panel = form.closest('.referrals-audience-panel');
        const list = panel?.querySelector('[data-referrals-rules]');
        const error = form.querySelector('[data-referrals-form-error]');
        const status = form.querySelector('[data-referrals-form-status]');
        const button = form.querySelector('button[type="submit"]');
        if (!list || !error || !status || !button) return;
        // The new row pushes the form down; keep it under the pointer.
        const top = form.getBoundingClientRect().top;
        form.dataset.saving = 'true';
        button.disabled = true;
        error.hidden = true;
        status.hidden = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok) {
                const messages = data.errors ? Object.values(data.errors).flat() : [];
                error.textContent = messages.length ? messages.join(' ') : form.dataset.requestFailed;
                error.hidden = false;
                return;
            }
            const template = document.createElement('template');
            template.innerHTML = data.html;
            const row = template.content.querySelector('[data-referral-rule-id]');
            if (!row || data.audience !== form.elements.audience.value) throw new Error('Invalid rule response');
            if (!list.querySelector(`[data-referral-rule-id="${row.dataset.referralRuleId}"]`)) {
                const next = [...list.querySelectorAll('[data-referral-rule-level]')]
                    .find((item) => Number(item.dataset.referralRuleLevel) > Number(row.dataset.referralRuleLevel));
                list.insertBefore(row, next || null);
            }
            list.querySelector('[data-referrals-empty]')?.remove();
            for (const name of ['level', 'item_id', 'amount']) form.elements.namedItem(name).value = '';
            fillPreview(form, null);
            status.textContent = data.message;
            status.hidden = false;
            form.elements.namedItem('level').focus({ preventScroll: true });
            window.scrollBy(0, form.getBoundingClientRect().top - top);
        } catch {
            error.textContent = form.dataset.requestFailed;
            error.hidden = false;
        } finally {
            form.dataset.saving = 'false';
            button.disabled = false;
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-referrals-delete-rule]')) return;
        event.preventDefault();
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
        const row = form.closest('[data-referral-rule-id]');
        const list = row?.closest('[data-referrals-rules]');
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) throw new Error('delete_failed');
            row?.remove();
            if (list) emptyMessage(list);
        } catch {
            form.submit();
        }
    });
})();
