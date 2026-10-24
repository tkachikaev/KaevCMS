(() => {
    'use strict';

    // Loaded in <head> without defer so the saved light/dark choice applies before the first paint.
    const root = document.documentElement;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const storageKey = 'kaevcms.account.scheme';
    const allowed = new Set(['light', 'dark']);

    const load = () => {
        try {
            const saved = window.localStorage.getItem(storageKey);

            return allowed.has(saved) ? saved : 'light';
        } catch {
            return 'light';
        }
    };

    // The button names what a click does: "switch to the dark theme" while the page is light.
    const synchronizeControls = () => {
        document.querySelectorAll('[data-account-scheme-toggle]').forEach((button) => {
            const next = root.dataset.accountScheme === 'dark' ? 'light' : 'dark';
            button.setAttribute('aria-checked', next === 'light' ? 'true' : 'false');
            const label = button.dataset[next === 'dark' ? 'labelDark' : 'labelLight'];
            if (label) {
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
            }
        });
    };

    const apply = (scheme) => {
        // Livewire replaces <html> attributes on every visit, including this enhancement flag.
        root.classList.add('kc-js');
        root.dataset.accountScheme = scheme;
        root.style.colorScheme = scheme;
        const themeColor = document.querySelector('meta[name="theme-color"]');
        if (themeColor) {
            themeColor.setAttribute('content', scheme === 'dark' ? '#0d0c10' : '#f4efe6');
        }
        synchronizeControls();
        document.dispatchEvent(new Event('kaev-classic:scheme-changed'));
    };

    let scheme = load();
    apply(scheme);

    const selectScheme = (next) => {
        scheme = next;
        try {
            window.localStorage.setItem(storageKey, scheme);
        } catch {
            // The choice still applies to this visit when storage is unavailable.
        }
        apply(scheme);
    };

    let appearanceChange = null;
    const cancelAppearanceChange = () => {
        const change = appearanceChange;
        if (change === null) return;
        appearanceChange = null;
        change.transition?.skipTransition();
        change.animation?.cancel();
        root.classList.remove('kc-theme-reveal');
        change.button.removeAttribute('aria-busy');
        selectScheme(change.next);
    };

    // Decode the destination artwork before taking its snapshot; a lazy night image
    // otherwise appears only after the wave. Slow or broken images never block switching.
    const prepareArtwork = async (next) => {
        const image = document.querySelector(`.kc-banner-art-${next}`);
        if (!(image instanceof HTMLImageElement)) return;
        image.loading = 'eager';
        let timer;
        try {
            await Promise.race([
                image.decode().catch(() => {}),
                new Promise(resolve => { timer = window.setTimeout(resolve, 1200); }),
            ]);
            if (image.complete) image.classList.add('is-revealed');
        } finally {
            window.clearTimeout(timer);
        }
    };

    const switchAppearance = async (button) => {
        if (appearanceChange !== null) return;
        const next = root.dataset.accountScheme === 'dark' ? 'light' : 'dark';
        if (reducedMotion.matches || typeof document.startViewTransition !== 'function'
            || typeof root.animate !== 'function') {
            selectScheme(next);
            return;
        }

        const change = { next, button, transition: null, animation: null };
        appearanceChange = change;
        button.setAttribute('aria-busy', 'true');
        const commit = () => {
            if (appearanceChange === change) selectScheme(next);
        };
        try {
            await prepareArtwork(next);
            if (appearanceChange !== change) return;
            root.classList.add('kc-theme-reveal');
            change.transition = document.startViewTransition(commit);
            // A skipped transition may reject ready while finished still resolves.
            const finished = change.transition.finished.catch(() => {});
            await change.transition.ready;
            if (appearanceChange !== change) return;
            change.animation = root.animate({
                opacity: [0, 1],
            }, {
                // A quick crossfade: one portrait melts into the other, the page follows.
                duration: 520,
                easing: 'cubic-bezier(.4,0,.2,1)',
                pseudoElement: '::view-transition-new(root)',
            });
            await change.animation.finished;
            await finished;
        } catch {
            // Cancellation or unsupported snapshot animation must still apply the choice.
            change.transition?.skipTransition();
            commit();
        } finally {
            if (appearanceChange === change) {
                appearanceChange = null;
                root.classList.remove('kc-theme-reveal');
                button.removeAttribute('aria-busy');
            }
        }
    };

    reducedMotion.addEventListener('change', () => {
        if (reducedMotion.matches) cancelAppearanceChange();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) cancelAppearanceChange();
    });

    // Motion hooks: `kc-js` lets images wait hidden until loaded (without JS they show at once);
    // `kc-entering` limits the sidebar entrance to the first paint, not to every Livewire visit.
    root.classList.add('kc-entering');
    window.addEventListener('load', () => window.setTimeout(() => {
        root.classList.remove('kc-entering');
        // A coin inside a closed menu is not rendered, so its fade never advances: settle it.
        document.querySelectorAll('img.account-wallet-coin-image').forEach((image) => {
            image.getAnimations().forEach((animation) => animation.finish());
        });
    }, 900), { once: true });

    // The banner art and the shared wallet coin both ease in once their image has arrived.
    const revealSelector = 'img[data-kc-reveal], img.account-wallet-coin-image';
    const reveal = (image) => image.classList.add('is-revealed');
    const revealLoaded = () => {
        document.querySelectorAll(revealSelector).forEach((image) => {
            if (image.complete) {
                reveal(image);
            }
        });
    };
    // A failed image is revealed too, so it never stays invisible.
    ['load', 'error'].forEach((type) => document.addEventListener(type, (event) => {
        if (event.target instanceof HTMLImageElement && event.target.matches(revealSelector)) {
            reveal(event.target);
        }
    }, true));
    document.addEventListener('DOMContentLoaded', revealLoaded);
    document.addEventListener('livewire:navigated', revealLoaded);

    // Keep the enhancement flag on the persisted menu, not on <html>, whose attributes
    // Livewire replaces. Otherwise the server's active backgrounds reappear after a swap.
    const navIndicator = () => {
        const nav = document.querySelector('.account-nav');
        if (!(nav instanceof HTMLElement)) {
            return null;
        }
        let indicator = nav.querySelector(':scope > .kc-nav-indicator');
        if (!(indicator instanceof HTMLElement)) {
            indicator = document.createElement('span');
            indicator.className = 'kc-nav-indicator';
            indicator.setAttribute('aria-hidden', 'true');
            nav.prepend(indicator);
        }
        nav.setAttribute('data-kc-nav-ready', '');

        return indicator;
    };
    let indicatorAnimation = null;
    const placeIndicator = (link, instant = false) => {
        const indicator = navIndicator();
        if (indicator === null) {
            return;
        }
        const nav = indicator.parentElement;
        const previousTarget = nav.querySelector('a.is-target');
        nav.querySelectorAll('a').forEach((item) => {
            if (item === link) {
                item.setAttribute('aria-current', 'page');
            } else {
                item.removeAttribute('aria-current');
                item.classList.remove('is-target');
            }
        });
        if (!(link instanceof HTMLElement)) {
            indicatorAnimation?.cancel();
            indicatorAnimation = null;
            nav.classList.remove('kc-nav-moving');
            indicator.classList.remove('is-placed');

            return;
        }
        // The swap and navigated hooks may both fire for the same destination.
        if (!instant && previousTarget === link && indicator.style.getPropertyValue('--kc-nav-y') === `${link.offsetTop}px`) {
            return;
        }
        const wasPlaced = indicator.classList.contains('is-placed');
        const fromY = new DOMMatrix(getComputedStyle(indicator).transform).m42;
        const toY = link.offsetTop;
        // Read the visible position before cancelling, so rapid visits continue smoothly.
        indicatorAnimation?.cancel();
        indicatorAnimation = null;
        indicator.style.setProperty('--kc-nav-y', `${toY}px`);
        indicator.style.height = `${link.offsetHeight}px`;
        indicator.classList.add('is-placed');
        link.classList.add('is-target');
        nav.classList.remove('kc-nav-moving');
        if (!instant && wasPlaced && Math.abs(fromY - toY) > 0.5 && !reducedMotion.matches && typeof indicator.animate === 'function') {
            nav.classList.add('kc-nav-moving');
            indicatorAnimation = indicator.animate(
                [{ transform: `translate3d(0, ${fromY}px, 0)` }, { transform: `translate3d(0, ${toY}px, 0)` }],
                { duration: 210, easing: 'cubic-bezier(.2, .7, .2, 1)' },
            );
            indicatorAnimation.onfinish = () => {
                nav.classList.remove('kc-nav-moving');
                indicatorAnimation = null;
            };
        }
    };
    // The persisted sidebar keeps the classes it was rendered with, so the current item is
    // found from the address: the longest menu path containing this page; the overview
    // (the `.exact` item) matches only itself.
    const pathOf = (url) => new URL(url, window.location.href).pathname.replace(/\/+$/, '') || '/';
    const activeLink = () => {
        const current = pathOf(window.location.href);
        let best = null;
        let bestLength = -1;
        document.querySelectorAll('.account-nav a[href]').forEach((link) => {
            const path = pathOf(link.getAttribute('href'));
            const exactOnly = link.hasAttribute('wire:current.exact');
            const matches = current === path || (!exactOnly && current.startsWith(`${path}/`));
            if (matches && path.length > bestLength) {
                best = link;
                bestLength = path.length;
            }
        });

        return best;
    };

    // Move only once the destination is loaded: a cancelled/failed visit must not select it.
    document.addEventListener('livewire:navigated', () => placeIndicator(activeLink()));
    document.addEventListener('DOMContentLoaded', () => placeIndicator(activeLink(), true));
    window.addEventListener('resize', () => placeIndicator(activeLink(), true));

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest('[data-account-scheme-toggle]') : null;
        if (!(target instanceof HTMLButtonElement)) {
            return;
        }

        void switchAppearance(target);
    });

    // Livewire navigation copies the new page's <html> attributes over this one; restore the
    // scheme in the same swap, before paint, so the page never flashes the other mode.
    document.addEventListener('livewire:navigating', (event) => {
        cancelAppearanceChange();
        event.detail?.onSwap?.(() => {
            apply(scheme);
            placeIndicator(activeLink());
        });
    });
    document.addEventListener('livewire:navigated', () => apply(scheme));
    document.addEventListener('DOMContentLoaded', synchronizeControls);
})();
