(() => {
    'use strict';

    const initialize = () => {
        if (document.querySelector('[data-admin-notification-center]') === null) {
            return null;
        }

        const closeMenus = (except = null) => {
            document.querySelectorAll('[data-admin-notification-center]').forEach((center) => {
                const menu = center.querySelector('[data-admin-notification-menu]');
                if (menu instanceof HTMLDetailsElement && menu !== except) {
                    menu.open = false;
                }
            });
        };

        const handleClick = (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) {
                return;
            }

            const menu = target.closest('[data-admin-notification-menu]');
            if (menu instanceof HTMLDetailsElement) {
                closeMenus(menu);
                return;
            }

            closeMenus();
        };

        const handleKeydown = (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            const openMenu = document.querySelector('[data-admin-notification-menu][open]');
            if (!(openMenu instanceof HTMLDetailsElement)) {
                return;
            }

            event.preventDefault();
            openMenu.open = false;
            openMenu.querySelector('summary')?.focus();
        };

        document.addEventListener('click', handleClick);
        document.addEventListener('keydown', handleKeydown);

        return () => {
            document.removeEventListener('click', handleClick);
            document.removeEventListener('keydown', handleKeydown);
        };
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('notifications', initialize);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
