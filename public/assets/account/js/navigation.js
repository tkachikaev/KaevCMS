(() => {
    const readyAttribute = 'data-account-navigation-ready';

    const closeMobileSidebar = () => {
        document.documentElement.classList.remove('account-sidebar-open');
        document.querySelector('[data-account-sidebar-toggle]')?.setAttribute('aria-expanded', 'false');
    };

    const profileMenus = () => document.querySelectorAll('.account-profile-menu[open]');
    const avatarModal = () => document.querySelector('[data-avatar-modal]');
    const operationModal = () => document.querySelector('[data-account-operation-modal]');
    const characterRescueModal = () => document.querySelector('[data-character-rescue-modal]');

    const closeProfileMenus = () => {
        profileMenus().forEach((menu) => menu.removeAttribute('open'));
    };

    const openAvatarModal = () => {
        const modal = avatarModal();
        if (!(modal instanceof HTMLDialogElement)) {
            return;
        }

        closeProfileMenus();
        closeMobileSidebar();

        if (!modal.open) {
            modal.showModal();
        }

        document.documentElement.classList.add('account-modal-open');
    };

    const closeOperationModal = () => {
        const modal = operationModal();
        if (modal instanceof HTMLDialogElement && modal.open) {
            modal.close();
        }
        document.documentElement.classList.remove('account-operation-modal-open');
    };

    const closeCharacterRescueModal = () => {
        const modal = characterRescueModal();
        if (modal instanceof HTMLDialogElement && modal.open) {
            modal.close();
        }
        document.documentElement.classList.remove('account-operation-modal-open');
    };

    const openCharacterRescueModal = (trigger) => {
        const modal = characterRescueModal();
        if (!(modal instanceof HTMLDialogElement) || !(trigger instanceof Element)) {
            return;
        }

        const form = modal.querySelector('[data-character-rescue-form]');
        const name = modal.querySelector('[data-character-rescue-name]');
        const location = modal.querySelector('[data-character-rescue-location]');
        const title = modal.querySelector('[data-character-rescue-title]');
        const description = modal.querySelector('[data-character-rescue-description]');
        const confirmation = modal.querySelector('[data-character-rescue-confirmation]');
        const onlineMessage = modal.querySelector('[data-character-rescue-online-message]');
        const submit = modal.querySelector('[data-character-rescue-submit]');
        const action = trigger.getAttribute('data-character-rescue-action') || '';
        const online = trigger.getAttribute('data-character-rescue-online') === '1';

        if (!(form instanceof HTMLFormElement) || action === '') {
            return;
        }

        form.action = online ? '' : action;
        form.dataset.characterRescueOnline = online ? '1' : '0';
        if (name) {
            name.textContent = trigger.getAttribute('data-character-rescue-name') || '—';
        }
        if (location) {
            location.textContent = trigger.getAttribute('data-character-rescue-location') || '—';
        }
        if (title) {
            title.textContent = online ? title.dataset.onlineText || '' : title.dataset.offlineText || '';
        }
        if (description) {
            description.textContent = online ? description.dataset.onlineText || '' : description.dataset.offlineText || '';
        }
        if (confirmation) {
            confirmation.toggleAttribute('hidden', online);
        }
        if (onlineMessage) {
            onlineMessage.toggleAttribute('hidden', !online);
        }
        if (submit instanceof HTMLButtonElement) {
            submit.disabled = online;
            submit.toggleAttribute('hidden', online);
        }

        closeProfileMenus();
        closeMobileSidebar();
        if (!modal.open) {
            modal.showModal();
        }
        document.documentElement.classList.add('account-operation-modal-open');
    };

    const initializeCharacterRescueModal = () => {
        const modal = characterRescueModal();
        if (!(modal instanceof HTMLDialogElement)) {
            return;
        }

        if (!modal.hasAttribute(readyAttribute)) {
            modal.setAttribute(readyAttribute, '');
            modal.addEventListener('close', () => {
                document.documentElement.classList.remove('account-operation-modal-open');
            });
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeCharacterRescueModal();
                }
            });
            modal.querySelector('[data-character-rescue-form]')?.addEventListener('submit', (event) => {
                if (event.currentTarget instanceof HTMLFormElement && event.currentTarget.dataset.characterRescueOnline === '1') {
                    event.preventDefault();
                }
            });
        }
    };

    const initializeOperationModal = () => {
        const modal = operationModal();
        if (!(modal instanceof HTMLDialogElement)) {
            document.documentElement.classList.remove('account-operation-modal-open');
            return;
        }

        if (!modal.hasAttribute(readyAttribute)) {
            modal.setAttribute(readyAttribute, '');
            modal.addEventListener('close', () => {
                document.documentElement.classList.remove('account-operation-modal-open');
            });
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeOperationModal();
                }
            });
        }

        if (modal.hasAttribute('data-account-operation-modal-auto-open') && !modal.open) {
            modal.removeAttribute('data-account-operation-modal-auto-open');
            closeProfileMenus();
            closeMobileSidebar();
            modal.showModal();
            document.documentElement.classList.add('account-operation-modal-open');
        }
    };

    const closeAvatarModal = () => {
        const modal = avatarModal();
        if (modal instanceof HTMLDialogElement && modal.open) {
            modal.close();
        }
        document.documentElement.classList.remove('account-modal-open');
    };

    const initializeAvatarModal = () => {
        const modal = avatarModal();
        if (!(modal instanceof HTMLDialogElement)) {
            document.documentElement.classList.remove('account-modal-open');
            return;
        }

        if (!modal.hasAttribute(readyAttribute)) {
            modal.setAttribute(readyAttribute, '');
            modal.addEventListener('close', () => {
                document.documentElement.classList.remove('account-modal-open');
            });
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeAvatarModal();
                }
            });
        }

        if (modal.hasAttribute('data-avatar-modal-auto-open') && !modal.open) {
            modal.removeAttribute('data-avatar-modal-auto-open');
            openAvatarModal();
        }
    };

    const initializeShell = () => {
        const sidebar = document.querySelector('[data-account-sidebar]');
        const toggle = document.querySelector('[data-account-sidebar-toggle]');

        if (toggle && !toggle.hasAttribute(readyAttribute)) {
            toggle.setAttribute(readyAttribute, '');
            toggle.addEventListener('click', () => {
                const willOpen = !document.documentElement.classList.contains('account-sidebar-open');
                document.documentElement.classList.toggle('account-sidebar-open', willOpen);
                toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        }

        if (sidebar && !sidebar.hasAttribute(readyAttribute)) {
            sidebar.setAttribute(readyAttribute, '');
            sidebar.addEventListener('click', (event) => {
                if (event.target instanceof Element && event.target.closest('a[wire\\:navigate]')) {
                    closeMobileSidebar();
                }
            });
        }

        closeProfileMenus();
        initializeAvatarModal();
        initializeOperationModal();
        initializeCharacterRescueModal();
    };

    const beginNavigation = () => {
        document.documentElement.classList.add('account-is-navigating');
        closeAvatarModal();
        closeOperationModal();
        closeCharacterRescueModal();
        closeMobileSidebar();
    };

    const finishNavigation = () => {
        initializeShell();

        window.requestAnimationFrame(() => {
            document.documentElement.classList.remove('account-is-navigating');
        });
    };

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const passwordToggle = event.target.closest('[data-password-toggle]');
        if (passwordToggle instanceof HTMLButtonElement) {
            const inputId = passwordToggle.getAttribute('data-password-toggle');
            const input = inputId ? document.getElementById(inputId) : null;
            if (input instanceof HTMLInputElement) {
                const willShow = input.type === 'password';
                input.type = willShow ? 'text' : 'password';
                passwordToggle.setAttribute('aria-pressed', willShow ? 'true' : 'false');
                passwordToggle.setAttribute('aria-label', passwordToggle.getAttribute(willShow ? 'data-hide-label' : 'data-show-label') || '');
                passwordToggle.textContent = passwordToggle.getAttribute(willShow ? 'data-hide-text' : 'data-show-text') || '';
                input.focus({ preventScroll: true });
            }
            return;
        }

        const openTrigger = event.target.closest('[data-avatar-modal-open]');
        if (openTrigger) {
            event.preventDefault();
            openAvatarModal();
            return;
        }

        if (event.target.closest('[data-account-operation-modal-close]')) {
            event.preventDefault();
            closeOperationModal();
            return;
        }

        const rescueTrigger = event.target.closest('[data-character-rescue-open]');
        if (rescueTrigger) {
            event.preventDefault();
            openCharacterRescueModal(rescueTrigger);
            return;
        }

        if (event.target.closest('[data-character-rescue-close]')) {
            event.preventDefault();
            closeCharacterRescueModal();
            return;
        }

        if (event.target.closest('[data-avatar-modal-close]')) {
            event.preventDefault();
            closeAvatarModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMobileSidebar();
            closeProfileMenus();
        }
    });

    document.addEventListener('pointerdown', (event) => {
        if (!document.documentElement.classList.contains('account-sidebar-open')) {
            return;
        }

        if (event.target instanceof Element && !event.target.closest('[data-account-sidebar], [data-account-sidebar-toggle]')) {
            closeMobileSidebar();
        }
    });

    document.addEventListener('livewire:navigate', beginNavigation);
    document.addEventListener('livewire:navigated', finishNavigation);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', finishNavigation, { once: true });
    } else {
        finishNavigation();
    }
})();
