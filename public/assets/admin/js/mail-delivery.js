(() => {
    'use strict';

    const initialize = () => {
        const panels = [...document.querySelectorAll('[data-mail-delivery-probe]')]
            .filter((panel) => panel.dataset.probeStatus === 'pending');

        if (panels.length === 0) {
            return;
        }

        const cleanups = panels.map((panel) => {
            const statusUrl = panel.dataset.probeStatusUrl;
            const maxAttempts = Number.parseInt(panel.dataset.probeMaxAttempts || '20', 10);
            const controller = new AbortController();
            let attempts = 0;
            let timeoutId = null;
            let active = true;

            const schedule = () => {
                if (active && attempts < maxAttempts) {
                    timeoutId = window.setTimeout(poll, 1000);
                } else if (active) {
                    window.location.reload();
                }
            };

            const poll = async () => {
                attempts += 1;

                try {
                    const response = await fetch(statusUrl, {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                        credentials: 'same-origin',
                        signal: controller.signal,
                    });

                    if (response.ok) {
                        const result = await response.json();

                        if (result.status !== 'pending') {
                            window.location.reload();
                            return;
                        }
                    }
                } catch (error) {
                    if (error instanceof DOMException && error.name === 'AbortError') {
                        return;
                    }
                }

                schedule();
            };

            timeoutId = window.setTimeout(poll, 700);

            return () => {
                active = false;
                controller.abort();
                if (timeoutId !== null) {
                    window.clearTimeout(timeoutId);
                }
            };
        });

        return () => cleanups.forEach((cleanup) => cleanup());
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('mail-delivery', initialize);
    } else {
        initialize();
    }
})();
