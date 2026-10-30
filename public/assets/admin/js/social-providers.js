(() => {
    'use strict';

    const reopenErrors = () => {
        const dialog = document.querySelector('[data-social-dialog][data-open-on-error="1"]');
        if (dialog instanceof HTMLDialogElement && !dialog.open) dialog.showModal();
    };
    document.addEventListener('livewire:navigated', reopenErrors);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', reopenErrors);
    else reopenErrors();
    document.addEventListener('livewire:navigating', () => {
        document.querySelectorAll('[data-social-dialog][open]').forEach(dialog => dialog.close());
    });
    const pending = new WeakSet();
    const updateProvider = (provider, resetSwitch = true) => {
        const row = document.querySelector(`[data-social-provider="${provider.key}"]`);
        if (!row) return;
        const badge = row.querySelector('[data-social-badge]');
        badge.textContent = JSON.parse(badge.dataset.labels)[provider.status];
        badge.className = 'status-badge status-badge-' + (provider.status === 'enabled' ? 'success' : provider.status === 'unconfigured' ? 'muted' : 'warning');
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (resetSwitch) checkbox.checked = provider.enabled;
        checkbox.disabled = !provider.enabled && (!provider.configured || !provider.integration_ready);
        checkbox.closest('label').classList.toggle('is-disabled', checkbox.disabled);
        row.querySelector('[data-social-toggle] input[type="hidden"]').disabled = checkbox.disabled;
        row.querySelectorAll('[name="client_secret"]').forEach(input => {
            input.value = '';
            input.placeholder = provider.secret_saved ? document.querySelector('[data-social-message]').dataset.secretSaved : '';
        });
        const removal = row.querySelector('.social-provider-remove-secret');
        if (removal) removal.hidden = !provider.secret_saved;
    };
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-social-save], [data-registration-save]')) return;
        event.preventDefault();
        if (pending.has(form)) return;
        pending.add(form);
        const message = document.querySelector('[data-social-message]');
        const dialog = form.closest('dialog');
        let error = (dialog || form).querySelector('[data-save-error-message]');
        if (!error) {
            error = document.createElement('p');
            error.dataset.saveErrorMessage = '';
            error.className = 'notice notice-error';
            error.setAttribute('role', 'alert');
            (dialog?.querySelector('.social-provider-form-wrap') || form).prepend(error);
        }
        error.hidden = true;
        message.textContent = '';
        const buttons = Array.from(form.elements).filter(el => el instanceof HTMLButtonElement && el.type === 'submit');
        const body = new FormData(form);
        let failureMessage = message.dataset.saveError;
        buttons.forEach(button => button.disabled = true);
        try {
            const response = await fetch(form.action, {
                method: 'POST', body, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error(message.dataset.saveError);
            const result = await response.json();
            if (!response.ok) {
                if (response.status === 422) failureMessage = Object.values(result.errors || {}).flat().join(' ') || failureMessage;
                throw new Error(failureMessage);
            }
            if (result.providers) Object.entries(result.providers).forEach(([key, provider]) => updateProvider({ ...provider, key }));
            if (result.provider) updateProvider({ ...result.provider, key: form.closest('[data-social-provider]').dataset.socialProvider }, result.id_changed);
            message.textContent = result.message;
            message.className = 'notice notice-success';
            dialog?.close();
        } catch {
            error.textContent = failureMessage;
            error.hidden = false;
        } finally {
            buttons.forEach(button => button.disabled = false);
            pending.delete(form);
        }
    });
    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-social-open]');
        if (opener) {
            const dialog = document.getElementById(opener.dataset.socialOpen);
            if (dialog instanceof HTMLDialogElement && !dialog.open) dialog.showModal();
        }
        const closer = event.target.closest('[data-social-close]');
        if (closer) closer.closest('dialog')?.close();
    });

    // Event delegation survives Livewire navigation; clipboard code is bundled locally.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-social-copy]');
        if (!button) return;
        const input = document.getElementById(button.dataset.socialCopy);
        if (!(input instanceof HTMLInputElement)) return;
        const copied = await window.KaevCMSAdminClipboard?.copy(input.value);
        let state = button.parentElement.querySelector('[data-social-copy-state]');
        if (!state) {
            state = document.createElement('span');
            state.dataset.socialCopyState = '';
            state.className = 'social-provider-copy-state';
            state.setAttribute('role', 'status');
            button.parentElement.appendChild(state);
        }
        state.textContent = copied ? button.dataset.socialCopySuccess : button.dataset.socialCopyFailure;
    });
})();
