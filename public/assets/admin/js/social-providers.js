(() => {
    'use strict';

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
