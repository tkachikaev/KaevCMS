(() => {
    'use strict';

    const initialize = () => {
        return (() => {
            'use strict';

            const dashboard = document.querySelector('[data-server-monitor-dashboard]');
            if (!dashboard || dashboard.dataset.autoRefresh !== '1') {
                return;
            }

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const refreshUrl = dashboard.dataset.refreshUrl;
            if (!csrfToken || !refreshUrl) {
                return;
            }

            const stateClasses = ['maintenance', 'online', 'configured', 'not_configured', 'offline', 'unknown'];
            const controller = new AbortController();
            let attempts = 0;
            let timeoutId = null;
            let active = true;

            const rowFor = (selector, dataKey, id) => Array.from(dashboard.querySelectorAll(selector))
                .find((row) => row.dataset[dataKey] === String(id));

            const applyState = (row, state, label) => {
                if (!row) {
                    return;
                }

                const dot = row.querySelector('[data-monitor-dot]');
                const stateElement = row.querySelector('[data-monitor-state]');

                if (dot) {
                    dot.classList.remove(...stateClasses);
                    dot.classList.add(state || 'unknown');
                }

                if (stateElement) {
                    stateElement.textContent = label;
                }
            };

            const applyMonitor = (payload) => {
                if (!active || !document.body.contains(dashboard)) {
                    return;
                }

                const total = dashboard.querySelector('[data-monitor-total-online]');
                const partial = dashboard.querySelector('[data-monitor-partial]');
                const updated = dashboard.querySelector('[data-monitor-updated]');

                if (total) {
                    total.textContent = payload.total_online_formatted;
                }

                if (partial) {
                    partial.hidden = !payload.partial;
                }

                if (updated) {
                    updated.textContent = payload.updated_label;
                }

                payload.game_servers.forEach((server) => {
                    const row = rowFor('[data-monitor-admin-game]', 'monitorAdminGame', server.id);
                    applyState(row, server.state, server.admin_state_label);

                    const details = row?.querySelector('[data-monitor-details]');
                    if (details) {
                        details.textContent = server.details_label;
                    }

                    const online = row?.querySelector('[data-monitor-online]');
                    if (online) {
                        online.textContent = server.admin_online_label;
                    }
                });

                payload.login_servers.forEach((server) => {
                    const row = rowFor('[data-monitor-admin-login]', 'monitorAdminLogin', server.id);
                    applyState(row, server.state, server.state_label);

                    const details = row?.querySelector('[data-monitor-details]');
                    if (details) {
                        details.textContent = server.details_label;
                    }
                });
            };

            const schedule = (callback, delay) => {
                if (active) {
                    timeoutId = window.setTimeout(callback, delay);
                }
            };

            const refresh = async () => {
                if (!active) {
                    return;
                }

                attempts += 1;

                try {
                    const response = await fetch(refreshUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        signal: controller.signal,
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        if ((response.status === 429 || response.status === 503) && attempts < 20) {
                            schedule(refresh, 1500);
                        }

                        return;
                    }

                    const payload = await response.json();
                    if (payload.monitor) {
                        applyMonitor(payload.monitor);
                    }

                    if (payload.refreshing && attempts < 20) {
                        schedule(refresh, 1000);
                    }
                } catch (error) {
                    if (!(error instanceof DOMException && error.name === 'AbortError')) {
                        // Leave the last known dashboard snapshot visible.
                    }
                }
            };

            const cleanup = () => {
                active = false;
                controller.abort();
                if (timeoutId !== null) {
                    window.clearTimeout(timeoutId);
                }
            };

            refresh();

            return cleanup;
        })();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('server-monitor', initialize);
    } else {
        initialize();
    }
})();
