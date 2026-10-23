(() => {
    'use strict';

    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    // The header button steps through the themes in this order; 'system' follows the device.
    const cycle = ['light', 'dark', 'system'];
    const allowed = new Set(cycle);
    const storageKey = root.dataset.adminAppearanceKey || 'kaevcms.admin.appearance';

    const loadPreference = () => {
        try {
            const saved = window.localStorage.getItem(storageKey);

            return allowed.has(saved) ? saved : 'light';
        } catch {
            return 'light';
        }
    };

    const resolvedScheme = (preference) => preference === 'system'
        ? (media.matches ? 'dark' : 'light')
        : preference;

    const nextPreference = (current) => cycle[(cycle.indexOf(current) + 1) % cycle.length];

    // The button names what a click does: "switch to the dark theme" while the page is light.
    const synchronizeControls = () => {
        document.querySelectorAll('[data-admin-appearance-toggle]').forEach((button) => {
            const next = nextPreference(root.dataset.adminAppearance || 'light');
            const label = button.dataset[`label${next.charAt(0).toUpperCase()}${next.slice(1)}`];
            if (label) {
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
            }
        });
    };

    const applyPreference = (preference) => {
        const normalized = allowed.has(preference) ? preference : 'light';
        const scheme = resolvedScheme(normalized);

        root.dataset.adminAppearance = normalized;
        root.dataset.adminColorScheme = scheme;
        root.style.colorScheme = scheme;

        const themeColor = document.querySelector('meta[name="theme-color"]');
        if (themeColor) {
            themeColor.setAttribute('content', scheme === 'dark' ? '#0b111a' : '#f3f6fa');
        }

        synchronizeControls();
    };

    const savePreference = (preference) => {
        try {
            window.localStorage.setItem(storageKey, preference);
        } catch {
            // Appearance still works for the current page when browser storage is unavailable.
        }
    };

    let preference = loadPreference();
    applyPreference(preference);

    // The browser cross-fades a snapshot of the whole page into the new theme, so every colour
    // changes in one smooth step; the header icon slides up and the next one rises from below
    // (see appearance.css). Element transitions are paused meanwhile so nothing animates twice.
    // Browsers without view transitions, or with reduced motion, simply switch.
    const switchTo = (next) => {
        const apply = () => {
            preference = next;
            savePreference(preference);
            applyPreference(preference);
        };

        if (reducedMotion.matches || typeof document.startViewTransition !== 'function') {
            apply();

            return;
        }

        root.classList.add('admin-theme-changing');
        const transition = document.startViewTransition(apply);
        transition.finished.finally(() => root.classList.remove('admin-theme-changing'));
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest('[data-admin-appearance-toggle]') : null;
        if (!(target instanceof HTMLButtonElement)) {
            return;
        }

        switchTo(nextPreference(preference));
    });

    media.addEventListener('change', () => {
        if (preference === 'system') {
            applyPreference(preference);
        }
    });

    document.addEventListener('DOMContentLoaded', synchronizeControls);
    document.addEventListener('livewire:navigated', () => applyPreference(preference));

    window.KaevCMSAdminAppearance = Object.freeze({
        current: () => preference,
        apply: (nextPreference) => {
            if (!allowed.has(nextPreference)) {
                return false;
            }

            switchTo(nextPreference);

            return true;
        },
    });
})();
