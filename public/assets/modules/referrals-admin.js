(() => {
    const switchTab = button => {
        const tabs = button.closest('[data-referrals-audience-tabs]');
        if (!tabs) return;
        const side = button.dataset.referralAudience;
        tabs.querySelectorAll('[data-referral-audience]').forEach(tab => {
            const active = tab === button;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            const panel = document.getElementById(tab.getAttribute('aria-controls'));
            if (panel) panel.hidden = !active;
        });
        const url = new URL(location.href);
        url.searchParams.set('audience', side);
        history.replaceState(history.state, '', url);
    };
    document.addEventListener('click', event => {
        const button = event.target instanceof Element && event.target.closest('[data-referral-audience]');
        if (button) switchTab(button);
    });
    document.addEventListener('keydown', event => {
        const button = event.target instanceof Element && event.target.closest('[data-referral-audience]');
        if (!button || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const tabs = [...button.parentElement.querySelectorAll('[data-referral-audience]')];
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (tabs.indexOf(button) + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[index].focus();
        switchTab(tabs[index]);
    });
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-referrals-add-rule]')) return;
        event.preventDefault();
        if (form.dataset.saving === 'true') return;
        const panel = form.closest('[role="tabpanel"]');
        const list = panel.querySelector('[data-referrals-rules]');
        const error = form.querySelector('[data-referrals-form-error]');
        const status = form.querySelector('[data-referrals-form-status]');
        const button = form.querySelector('button[type="submit"]');
        const top = form.getBoundingClientRect().top;
        const fields = [...form.querySelectorAll('input[type=number]')];
        const readOnlyStates = fields.map(field => field.readOnly);
        form.dataset.saving = 'true';
        button.disabled = true;
        fields.forEach(field => field.readOnly = true);
        error.hidden = true;
        status.hidden = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
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
                const next = [...list.querySelectorAll('[data-referral-rule-level]')].find(item => Number(item.dataset.referralRuleLevel) > Number(row.dataset.referralRuleLevel));
                list.insertBefore(row, next || null);
            }
            list.querySelector('[data-referrals-empty]')?.remove();
            for (const name of ['level', 'item_id', 'amount']) form.elements.namedItem(name).value = '';
            status.textContent = data.message;
            status.hidden = false;
            form.elements.namedItem('level').focus({preventScroll: true});
            window.scrollBy(0, form.getBoundingClientRect().top - top);
        } catch {
            error.textContent = form.dataset.requestFailed;
            error.hidden = false;
        } finally {
            form.dataset.saving = 'false';
            button.disabled = false;
            fields.forEach((field, index) => field.readOnly = readOnlyStates[index]);
        }
    });
})();
