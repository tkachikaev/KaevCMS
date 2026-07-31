(() => {
    const storageKey = 'kaevcms.admin.menu.groups';
    const initializedAttribute = 'data-admin-navigation-ready';
    const synchronizingAttribute = 'data-admin-menu-synchronizing';
    const mobileQuery = window.matchMedia('(max-width: 760px)');
    let lastMenuTrigger = null;

    const loadSavedState = () => {
        try {
            const savedValue = window.localStorage.getItem(storageKey);
            const parsedState = JSON.parse(savedValue ?? '{}');

            if (parsedState && typeof parsedState === 'object' && !Array.isArray(parsedState)) {
                return parsedState;
            }
        } catch {
            // The menu remains functional when browser storage is unavailable.
        }

        return {};
    };

    const saveState = (sidebar) => {
        const state = {};

        sidebar.querySelectorAll('[data-admin-menu-group]').forEach((group) => {
            state[group.dataset.adminMenuGroup] = group.open;
        });

        try {
            window.localStorage.setItem(storageKey, JSON.stringify(state));
        } catch {
            // The menu remains functional when browser storage is unavailable.
        }
    };

    const currentMenuItem = (sidebar) => sidebar.querySelector('.admin-menu-item.active, .admin-menu-item[data-current]');

    const normalizePath = (path) => path.length > 1 ? path.replace(/\/$/, '') : path;

    const synchronizeSettingsLink = (sidebar) => {
        const settingsLink = sidebar.querySelector('[data-admin-settings-link]');

        if (!settingsLink) {
            return;
        }

        const settingsPath = normalizePath(new URL(settingsLink.href, window.location.origin).pathname);
        const currentPath = normalizePath(window.location.pathname);
        const excludedSections = ['mail', 'game-server', 'login-server'];
        const isInsideSettings = currentPath === settingsPath || currentPath.startsWith(`${settingsPath}/`);
        const isExcludedSection = excludedSections.some((section) => {
            const excludedPath = `${settingsPath}/${section}`;

            return currentPath === excludedPath || currentPath.startsWith(`${excludedPath}/`);
        });

        settingsLink.toggleAttribute('data-current', isInsideSettings && !isExcludedSection);
    };

    const menuIsOpen = () => document.documentElement.classList.contains('admin-mobile-menu-open');

    const synchronizeDrawerAccessibility = () => {
        const sidebar = document.querySelector('[data-admin-sidebar]');
        const backdrop = document.querySelector('.admin-sidebar-backdrop');
        const open = mobileQuery.matches && menuIsOpen();

        if (sidebar) {
            sidebar.setAttribute('aria-hidden', mobileQuery.matches && !open ? 'true' : 'false');
        }

        if (backdrop) {
            backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
        }

        document.querySelectorAll('[data-admin-menu-open]').forEach((button) => {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    };

    const closeMobileMenu = ({ restoreFocus = true } = {}) => {
        const wasOpen = menuIsOpen();
        document.documentElement.classList.remove('admin-mobile-menu-open');
        synchronizeDrawerAccessibility();

        if (wasOpen && restoreFocus && lastMenuTrigger instanceof HTMLElement && lastMenuTrigger.isConnected) {
            lastMenuTrigger.focus();
        }

        if (wasOpen) {
            lastMenuTrigger = null;
        }
    };

    const openMobileMenu = (trigger) => {
        if (!mobileQuery.matches) {
            return;
        }

        lastMenuTrigger = trigger instanceof HTMLElement ? trigger : document.activeElement;
        document.documentElement.classList.add('admin-mobile-menu-open');
        synchronizeDrawerAccessibility();

        window.requestAnimationFrame(() => {
            document.querySelector('[data-admin-sidebar] [data-admin-menu-close]')?.focus();
        });
    };

    const synchronizeSidebar = () => {
        const sidebar = document.querySelector('[data-admin-sidebar]');

        if (!sidebar) {
            return;
        }

        synchronizeSettingsLink(sidebar);

        const savedState = loadSavedState();
        const groups = Array.from(sidebar.querySelectorAll('[data-admin-menu-group]'));

        sidebar.setAttribute(synchronizingAttribute, '');

        groups.forEach((group) => {
            const containsCurrentItem = group.querySelector('.admin-menu-item.active, .admin-menu-item[data-current]') !== null;

            if (containsCurrentItem) {
                group.open = true;
            } else {
                group.open = savedState[group.dataset.adminMenuGroup] ?? false;
            }

            if (!group.hasAttribute(initializedAttribute)) {
                group.setAttribute(initializedAttribute, '');
                group.addEventListener('toggle', () => {
                    if (!sidebar.hasAttribute(synchronizingAttribute)) {
                        saveState(sidebar);
                    }
                });
            }
        });

        window.requestAnimationFrame(() => {
            sidebar.removeAttribute(synchronizingAttribute);
        });

        const activeItem = currentMenuItem(sidebar);
        const isDesktopSidebar = !mobileQuery.matches;

        if (isDesktopSidebar && activeItem && typeof activeItem.scrollIntoView === 'function') {
            const sidebarRect = sidebar.getBoundingClientRect();
            const itemRect = activeItem.getBoundingClientRect();
            const isVisible = itemRect.top >= sidebarRect.top && itemRect.bottom <= sidebarRect.bottom;

            if (!isVisible) {
                activeItem.scrollIntoView({ block: 'nearest' });
            }
        }

        synchronizeDrawerAccessibility();
    };

    const beginNavigation = () => {
        closeMobileMenu({ restoreFocus: false });
        document.documentElement.classList.add('admin-is-navigating');
    };

    const finishNavigation = () => {
        closeMobileMenu({ restoreFocus: false });
        synchronizeSidebar();

        window.requestAnimationFrame(() => {
            document.documentElement.classList.remove('admin-is-navigating');
        });
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        const openButton = target.closest('[data-admin-menu-open]');
        if (openButton) {
            openMobileMenu(openButton);

            return;
        }

        if (target.closest('[data-admin-menu-close]')) {
            closeMobileMenu();

            return;
        }

        if (mobileQuery.matches && target.closest('[data-admin-sidebar] [data-admin-menu-link]')) {
            closeMobileMenu({ restoreFocus: false });
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menuIsOpen()) {
            event.preventDefault();
            closeMobileMenu();
        }
    });

    mobileQuery.addEventListener('change', () => {
        closeMobileMenu({ restoreFocus: false });
        synchronizeSidebar();
    });

    document.addEventListener('livewire:navigate', beginNavigation);
    document.addEventListener('livewire:navigated', finishNavigation);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', finishNavigation, { once: true });
    } else {
        finishNavigation();
    }
})();
