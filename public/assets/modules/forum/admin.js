(() => {
    'use strict';

    // Removing a moderator asks for confirmation; the dialog form posts the removal.
    // Delegated once so it survives Livewire navigation.
    if (document.documentElement.dataset.forumAdminInstalled === '1') return;
    document.documentElement.dataset.forumAdminInstalled = '1';

    document.addEventListener('click', (event) => {
        const dialog = document.querySelector('[data-forum-moderator-dialog]');
        if (!(dialog instanceof HTMLDialogElement)) return;

        const opener = event.target.closest('[data-forum-moderator-remove]');
        if (opener) {
            const form = dialog.querySelector('[data-forum-moderator-form]');
            const name = dialog.querySelector('[data-forum-moderator-name]');
            if (!(form instanceof HTMLFormElement)) return;
            form.action = opener.dataset.url ?? '';
            if (name) name.textContent = opener.dataset.name ?? '';
            dialog.showModal();
            return;
        }

        if (event.target.closest('[data-forum-moderator-cancel]') || event.target === dialog) {
            dialog.close();
        }
    });
})();
