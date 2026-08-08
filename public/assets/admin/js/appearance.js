(() => {
    'use strict';

    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');
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

    const synchronizeControls = (preference) => {
        document.querySelectorAll('[data-admin-appearance-option]').forEach((button) => {
            const active = button.dataset.adminAppearanceOption === preference;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
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

        synchronizeControls(normalized);
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

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest('[data-admin-appearance-option]') : null;
        if (!(target instanceof HTMLButtonElement)) {
            return;
        }

        const nextPreference = target.dataset.adminAppearanceOption;
        if (!allowed.has(nextPreference)) {
            return;
        }

        preference = nextPreference;
        savePreference(preference);
        applyPreference(preference);
    });

    media.addEventListener('change', () => {
        if (preference === 'system') {
            applyPreference(preference);
        }
    });

    document.addEventListener('livewire:navigated', () => applyPreference(preference));

    window.KaevCMSAdminAppearance = Object.freeze({
        current: () => preference,
        apply: (nextPreference) => {
            if (!allowed.has(nextPreference)) {
                return false;
            }

            preference = nextPreference;
            savePreference(preference);
            applyPreference(preference);

            return true;
        },
    });
})();
