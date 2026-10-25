(() => {
    'use strict';

    const initialize = () => {
        const toolbar = document.querySelector('.admin-user');
        if (!toolbar) return null;

        const selector = '.admin-language-menu, .admin-account-menu, [data-admin-notification-center] > .admin-notification-menu';
        const menus = [...toolbar.querySelectorAll(selector)];
        const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const controller = new AbortController();
        const { signal } = controller;
        const animations = new Map();
        const departing = new Map();
        const panelOf = menu => menu.querySelector(':scope > div');
        const summaryOf = menu => menu.querySelector(':scope > summary');
        const cancelEntry = (menu) => {
            animations.get(menu)?.cancel();
            animations.delete(menu);
        };
        const removeDeparture = (menu) => {
            const previous = departing.get(menu);
            if (!previous) return;
            previous.animation.cancel();
            previous.element.remove();
            departing.delete(menu);
        };
        const align = () => {
            const top = toolbar.getBoundingClientRect().bottom + 8;
            // scrollbar-gutter reserves layout space even when clientWidth includes it.
            const viewport = document.documentElement.getBoundingClientRect();
            const viewportWidth = viewport.width;
            menus.forEach(menu => {
                const panel = panelOf(menu);
                if (!panel) return;
                // Livewire morphs the panel on filtering/polling; details has wire:ignore.self.
                // Keep the anchor on that stable container so an update cannot reset it.
                menu.style.setProperty('--admin-dropdown-top', top + 'px');
                if (menu.open) {
                    const width = panel.getBoundingClientRect().width;
                    const desired = viewport.right - menu.getBoundingClientRect().right;
                    const right = Math.max(12, Math.min(desired, viewportWidth - width - 12));
                    menu.style.setProperty('--admin-dropdown-right', right + 'px');
                }
            });
        };
        const close = (menu, animate = true) => {
            if (!menu.open) return;
            const panel = panelOf(menu);
            const bounds = panel?.getBoundingClientRect();
            const appearance = panel ? getComputedStyle(panel) : null;
            const opacity = appearance?.opacity ?? '1';
            cancelEntry(menu);
            removeDeparture(menu);
            if (animate && !motion.matches && panel && typeof panel.animate === 'function') {
                // Close the real menu immediately; an inert copy finishes fading without
                // leaving a second menu open or its controls available to the keyboard.
                const copy = panel.cloneNode(true);
                copy.setAttribute('aria-hidden', 'true');
                copy.setAttribute('inert', '');
                copy.setAttribute('wire:ignore', '');
                copy.setAttribute('data-admin-dropdown-departing', '');
                [copy, ...copy.querySelectorAll('[id]')].forEach(element => element.removeAttribute('id'));
                Object.assign(copy.style, { top: bounds.top + 'px', left: bounds.left + 'px', right: 'auto',
                    width: bounds.width + 'px', height: 'auto', maxHeight: appearance.maxHeight,
                    margin: '0', pointerEvents: 'none',
                    display: appearance.display, textAlign: appearance.textAlign });
                copy.style.setProperty('--admin-dropdown-top', bounds.top + 'px');
                if (panel.scrollHeight <= panel.clientHeight && panel.scrollWidth <= panel.clientWidth) {
                    copy.style.overflow = 'clip';
                }
                // Preserve the header's inherited typography and scrollbar geometry while fading.
                toolbar.append(copy);
                copy.scrollTop = panel.scrollTop;
                copy.scrollLeft = panel.scrollLeft;
                const copiedChildren = copy.querySelectorAll('*');
                panel.querySelectorAll('*').forEach((element, index) => {
                    copiedChildren[index].scrollTop = element.scrollTop;
                    copiedChildren[index].scrollLeft = element.scrollLeft;
                });
                const animation = copy.animate([
                    { opacity, transform: 'translateY(0)' },
                    { opacity: 0, transform: 'translateY(-4px)' },
                ], { duration: 200, easing: 'ease-out', fill: 'forwards' });
                departing.set(menu, { element: copy, animation });
                animation.addEventListener('finish', () => removeDeparture(menu), { once: true });
            }
            menu.open = false;
            summaryOf(menu)?.setAttribute('aria-expanded', 'false');
        };
        const open = (menu) => {
            menus.forEach(other => { if (other !== menu) close(other); });
            const previous = departing.get(menu);
            const opacity = previous ? getComputedStyle(previous.element).opacity : '0';
            removeDeparture(menu);
            cancelEntry(menu);
            menu.open = true;
            summaryOf(menu)?.setAttribute('aria-expanded', 'true');
            align();
            const panel = panelOf(menu);
            if (panel && !motion.matches && typeof panel.animate === 'function') {
                const animation = panel.animate([
                    { opacity, transform: 'translateY(-4px)' },
                    { opacity: 1, transform: 'translateY(0)' },
                ], { duration: 200, easing: 'cubic-bezier(.2, .7, .3, 1)' });
                animations.set(menu, animation);
                animation.addEventListener('finish', () => animations.delete(menu), { once: true });
            }
        };

        menus.forEach(menu => {
            menu.setAttribute('data-admin-dropdown-ready', '');
            panelOf(menu)?.classList.add('admin-topbar-panel');
            summaryOf(menu)?.setAttribute('aria-expanded', menu.open ? 'true' : 'false');
        });
        document.addEventListener('click', (event) => {
            const summary = event.target instanceof Element ? event.target.closest('summary') : null;
            const menu = summary?.parentElement;
            if (!menus.includes(menu)) return;
            event.preventDefault();
            if (menu.open) close(menu);
            else open(menu);
        }, { signal });
        document.addEventListener('pointerdown', (event) => {
            menus.forEach(menu => { if (!menu.contains(event.target)) close(menu); });
        }, { signal });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            const menu = menus.find(item => item.open);
            if (!menu) return;
            event.preventDefault();
            close(menu);
            summaryOf(menu)?.focus();
        }, { signal });
        document.addEventListener('toggle', (event) => {
            const menu = event.target;
            if (!menus.includes(menu)) return;
            summaryOf(menu)?.setAttribute('aria-expanded', menu.open ? 'true' : 'false');
            if (menu.open) {
                menus.forEach(other => { if (other !== menu) close(other); });
                align();
            }
        }, { capture: true, signal });
        window.addEventListener('resize', align, { signal });
        window.addEventListener('scroll', align, { capture: true, passive: true, signal });
        motion.addEventListener('change', () => {
            menus.forEach(menu => { cancelEntry(menu); removeDeparture(menu); });
        }, { signal });
        const observer = new ResizeObserver(align);
        observer.observe(toolbar);
        const changes = new MutationObserver(align);
        changes.observe(toolbar, { childList: true, subtree: true });
        toolbar.querySelectorAll('.admin-account-dropdown > a').forEach(link => {
            if (new URL(link.href).pathname === location.pathname) link.setAttribute('aria-current', 'page');
        });
        align();

        return () => {
            controller.abort();
            observer.disconnect();
            changes.disconnect();
            menus.forEach(menu => {
                close(menu, false);
                cancelEntry(menu);
                removeDeparture(menu);
                menu.removeAttribute('data-admin-dropdown-ready');
            });
        };
    };

    if (window.KaevCMSAdmin?.registerPage) window.KaevCMSAdmin.registerPage('notifications', initialize);
    else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
