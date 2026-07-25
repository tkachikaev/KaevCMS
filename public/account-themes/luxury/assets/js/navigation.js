(() => {
    const readyAttribute = 'data-account-navigation-ready';

    const closeMobileSidebar = () => {
        document.documentElement.classList.remove('account-sidebar-open');
        document.querySelector('[data-account-sidebar-toggle]')?.setAttribute('aria-expanded', 'false');
    };

    const profileMenus = () => document.querySelectorAll('.account-profile-menu[open]');
    const avatarModal = () => document.querySelector('[data-avatar-modal]');

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
    };

    const beginNavigation = () => {
        document.documentElement.classList.add('account-is-navigating');
        closeAvatarModal();
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

        const openTrigger = event.target.closest('[data-avatar-modal-open]');
        if (openTrigger) {
            event.preventDefault();
            openAvatarModal();
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
