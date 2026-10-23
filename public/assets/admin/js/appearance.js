(() => {
    'use strict';

    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    // 'system' follows the device; it is no longer offered in the header, but a saved choice
    // keeps working until the administrator switches the theme with the button.
    const allowed = new Set(['light', 'dark', 'system']);
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

    // The button names what a click does: "switch to the dark theme" while the page is light.
    const synchronizeControls = () => {
        document.querySelectorAll('[data-admin-appearance-toggle]').forEach((button) => {
            const label = root.dataset.adminColorScheme === 'dark' ? button.dataset.labelLight : button.dataset.labelDark;
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

    // Colours fade for a moment instead of snapping to the other theme.
    let changingTimer = null;
    const switchTo = (nextPreference) => {
        if (!reducedMotion.matches) {
            root.classList.add('admin-theme-changing');
            window.clearTimeout(changingTimer);
            changingTimer = window.setTimeout(() => root.classList.remove('admin-theme-changing'), 300);
        }
        preference = nextPreference;
        savePreference(preference);
        applyPreference(preference);
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest('[data-admin-appearance-toggle]') : null;
        if (!(target instanceof HTMLButtonElement)) {
            return;
        }

        switchTo(resolvedScheme(preference) === 'dark' ? 'light' : 'dark');
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
