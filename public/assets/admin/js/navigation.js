(() => {
    const storageKey = 'kaevcms.admin.menu.groups';
    const legacyStorageKey = 'l2forge.admin.menu.groups';
    const initializedAttribute = 'data-admin-navigation-ready';
    const synchronizingAttribute = 'data-admin-menu-synchronizing';

    const loadSavedState = () => {
        try {
            const savedValue = window.localStorage.getItem(storageKey) ?? window.localStorage.getItem(legacyStorageKey);
            const parsedState = JSON.parse(savedValue ?? '{}');

            if (savedValue !== null && window.localStorage.getItem(storageKey) === null) {
                window.localStorage.setItem(storageKey, savedValue);
                window.localStorage.removeItem(legacyStorageKey);
            }

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
        const isDesktopSidebar = window.matchMedia('(min-width: 761px)').matches;

        if (isDesktopSidebar && activeItem && typeof activeItem.scrollIntoView === 'function') {
            const sidebarRect = sidebar.getBoundingClientRect();
            const itemRect = activeItem.getBoundingClientRect();
            const isVisible = itemRect.top >= sidebarRect.top && itemRect.bottom <= sidebarRect.bottom;

            if (!isVisible) {
                activeItem.scrollIntoView({ block: 'nearest' });
            }
        }
    };

    const beginNavigation = () => {
        document.documentElement.classList.add('admin-is-navigating');
    };

    const finishNavigation = () => {
        synchronizeSidebar();

        window.requestAnimationFrame(() => {
            document.documentElement.classList.remove('admin-is-navigating');
        });
    };

    document.addEventListener('livewire:navigate', beginNavigation);
    document.addEventListener('livewire:navigated', finishNavigation);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', finishNavigation, { once: true });
    } else {
        finishNavigation();
    }
})();
