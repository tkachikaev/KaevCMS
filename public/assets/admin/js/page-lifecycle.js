(() => {
    'use strict';

    const registry = new Map();
    let navigationEpoch = 0;
    let pageReady = document.readyState !== 'loading';

    const cleanupEntry = (entry) => {
        if (typeof entry.cleanup === 'function') {
            try {
                entry.cleanup();
            } catch {
                // A page cleanup must never block navigation.
            }
        }

        entry.cleanup = null;
        entry.lastRunEpoch = null;
    };

    const initializeEntry = (entry) => {
        if (!pageReady || entry.lastRunEpoch === navigationEpoch) {
            return;
        }

        cleanupEntry(entry);

        try {
            const cleanup = entry.initialize();
            entry.cleanup = typeof cleanup === 'function' ? cleanup : null;
            entry.lastRunEpoch = navigationEpoch;
        } catch (error) {
            window.console?.error?.(`Unable to initialize admin page script: ${entry.name}`, error);
        }
    };

    const initializeAll = () => {
        pageReady = true;
        registry.forEach(initializeEntry);
    };

    const cleanupAll = () => {
        registry.forEach(cleanupEntry);
    };

    window.KaevCMSAdmin = Object.assign(window.KaevCMSAdmin ?? {}, {
        registerPage(name, initialize) {
            if (typeof name !== 'string' || name === '' || typeof initialize !== 'function') {
                return;
            }

            const existing = registry.get(name);
            if (existing) {
                cleanupEntry(existing);
                existing.initialize = initialize;
                initializeEntry(existing);
                return;
            }

            const entry = {
                name,
                initialize,
                cleanup: null,
                lastRunEpoch: null,
            };

            registry.set(name, entry);
            initializeEntry(entry);
        },
    });

    document.addEventListener('livewire:navigating', () => {
        cleanupAll();
        navigationEpoch += 1;
        pageReady = false;
    });

    document.addEventListener('livewire:navigated', initializeAll);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAll, { once: true });
    } else {
        queueMicrotask(initializeAll);
    }
})();
