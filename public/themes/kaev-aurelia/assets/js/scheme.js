(() => {
    'use strict';

    // Loaded in <head> without defer so the saved light/dark choice applies before the first paint.
    const root = document.documentElement;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const mobileArt = window.matchMedia('(max-width: 680px)');
    const storageKey = 'kaevcms.site.scheme';
    const allowed = new Set(['light', 'dark']);

    const load = () => {
        try {
            const saved = window.localStorage.getItem(storageKey);

            return allowed.has(saved) ? saved : 'light';
        } catch {
            return 'light';
        }
    };

    // Every control names what a click does: "switch to the dark theme" while the page is light.
    const synchronizeControls = () => {
        const next = root.dataset.siteScheme === 'dark' ? 'light' : 'dark';
        document.querySelectorAll('[data-site-scheme-toggle]').forEach((button) => {
            button.setAttribute('aria-checked', next === 'light' ? 'true' : 'false');
            const label = button.dataset[next === 'dark' ? 'labelDark' : 'labelLight'];
            if (label) {
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
                button.querySelector('[data-scheme-label]')?.replaceChildren(label);
            }
        });
    };

    const apply = (scheme) => {
        root.dataset.siteScheme = scheme;
        root.style.colorScheme = scheme;
        const themeColor = document.querySelector('meta[name="theme-color"]');
        if (themeColor) {
            themeColor.setAttribute('content', scheme === 'dark' ? '#0d0c10' : '#f8f4ec');
        }
        synchronizeControls();
        document.dispatchEvent(new Event('kaev-aurelia:scheme-changed'));
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
        root.classList.remove('site-theme-reveal');
        change.button.removeAttribute('aria-busy');
        selectScheme(change.next);
    };

    // Decode the destination artwork before taking its snapshot, otherwise the portrait would
    // appear only after the wave. Slow or broken images never block switching.
    const prepareArtwork = async (next, button) => {
        const source = next === 'dark'
            ? button.dataset.darkArt
            : (mobileArt.matches ? button.dataset.lightArtMobile : button.dataset.lightArt);
        if (!source) return;
        const image = new Image();
        image.src = source;
        let timer;
        try {
            await Promise.race([
                image.decode().catch(() => {}),
                new Promise((resolve) => { timer = window.setTimeout(resolve, 1200); }),
            ]);
        } finally {
            window.clearTimeout(timer);
        }
    };

    const switchAppearance = async (button) => {
        if (appearanceChange !== null) return;
        const next = root.dataset.siteScheme === 'dark' ? 'light' : 'dark';
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
            await prepareArtwork(next, button);
            if (appearanceChange !== change) return;
            root.classList.add('site-theme-reveal');
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
            // Cancellation or an unsupported snapshot animation must still apply the choice.
            change.transition?.skipTransition();
            commit();
        } finally {
            if (appearanceChange === change) {
                appearanceChange = null;
                root.classList.remove('site-theme-reveal');
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

    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-site-scheme-toggle]') : null;
        if (button instanceof HTMLButtonElement) {
            switchAppearance(button);
        }
    });

    // Full page loads only: hold the portrait and copy back until the artwork is decoded, then let
    // them ease in. Livewire visits keep the persisted scene and never get the intro class.
    if (!reducedMotion.matches) {
        root.classList.add('ka-intro');
        let finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            root.classList.add('ka-ready');
            window.setTimeout(() => root.classList.remove('ka-intro', 'ka-ready'), 3200);
        };
        const start = async () => {
            const art = document.querySelector('.aurelia-scene .hero-art');
            const source = art ? getComputedStyle(art).backgroundImage.match(/url\(["']?(.+?)["']?\)/)?.[1] : null;
            if (source) {
                const image = new Image();
                image.src = source;
                let timer;
                try {
                    await Promise.race([
                        image.decode().catch(() => {}),
                        new Promise((resolve) => { timer = window.setTimeout(resolve, 2000); }),
                    ]);
                } finally {
                    window.clearTimeout(timer);
                }
            }
            window.requestAnimationFrame(() => window.requestAnimationFrame(finish));
        };
        document.addEventListener('DOMContentLoaded', start);
        window.setTimeout(finish, 3500);
    }

    // Livewire replaces the <html> attributes on every visit: restore the scheme in the same
    // swap, before paint, so the page never flashes the other mode.
    document.addEventListener('livewire:navigate', cancelAppearanceChange);
    document.addEventListener('livewire:navigating', (event) => {
        event.detail?.onSwap?.(() => apply(scheme));
    });
    document.addEventListener('livewire:navigated', () => apply(scheme));
    document.addEventListener('DOMContentLoaded', synchronizeControls);
})();
