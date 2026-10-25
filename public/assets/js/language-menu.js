(() => {
    'use strict';

    // Language menus (<details data-language-menu>) on the website, the player account and the
    // administration. Without this script the menus still open and close by click; it only
    // closes them on an outside click or Escape and keeps one open at a time.
    if (window.KaevCMSLanguageMenu) return;
    window.KaevCMSLanguageMenu = true;

    // Administration coordinates language, account and notification menus together.
    const openMenus = () => document.querySelectorAll('details[data-language-menu][open]:not([data-admin-dropdown-ready])');

    document.addEventListener('pointerdown', (event) => {
        const target = event.target instanceof Node ? event.target : null;
        openMenus().forEach((menu) => {
            if (!target || !menu.contains(target)) menu.open = false;
        });
    });

    document.addEventListener('toggle', (event) => {
        const menu = event.target;
        if (!(menu instanceof HTMLDetailsElement) || !menu.matches('[data-language-menu]') || menu.hasAttribute('data-admin-dropdown-ready') || !menu.open) return;
        openMenus().forEach((other) => {
            if (other !== menu) other.open = false;
        });
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const menu = openMenus()[0];
        if (!(menu instanceof HTMLDetailsElement)) return;
        menu.open = false;
        menu.querySelector('summary')?.focus();
    });
})();
