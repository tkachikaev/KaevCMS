(() => {
    'use strict';

    // One row menu open at a time. It closes on an outside click, Escape or after a choice, and the
    // click that closes it does nothing else, so a menu covering the next row can never cause a
    // misclick on a neighbouring action.
    if (window.KaevCMSUniversalDriverMenus) return;
    window.KaevCMSUniversalDriverMenus = true;

    const openMenus = () => [...document.querySelectorAll('[data-ud-menu][open]')];
    const closeMenus = (except = null) => openMenus().forEach((menu) => {
        if (menu !== except) menu.removeAttribute('open');
    });

    document.addEventListener('toggle', (event) => {
        const menu = event.target;
        if (menu instanceof HTMLElement && menu.matches('[data-ud-menu]') && menu.open) closeMenus(menu);
    }, true);

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (target === null || openMenus().length === 0) return;

        if (target.closest('.ud-menu-items button')) {
            // The action runs through Livewire; the menu has done its job.
            window.setTimeout(() => closeMenus(), 0);

            return;
        }

        if (target.closest('[data-ud-menu] > summary')) return;

        // Outside the menu: close it and swallow the click.
        closeMenus();
        event.preventDefault();
        event.stopPropagation();
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const [menu] = openMenus();
        if (menu) {
            menu.removeAttribute('open');
            menu.querySelector('summary')?.focus();
        }
    });
})();
