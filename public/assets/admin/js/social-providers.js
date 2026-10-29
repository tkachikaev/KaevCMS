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
    document.addEventListener('change', event => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.matches('[data-social-toggle] input[type="checkbox"]')) return;
        input.form.requestSubmit();
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
