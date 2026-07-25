document.addEventListener('DOMContentLoaded', () => {
    const menuButton = document.querySelector('.menu-toggle');
    const menu = document.querySelector('.main-nav');

    if (menuButton && menu) {
        menuButton.addEventListener('click', () => {
            const open = menu.classList.toggle('open');
            menuButton.setAttribute('aria-expanded', String(open));
        });
    }

    const monitor = document.querySelector('[data-server-monitor]');
    if (!monitor || monitor.dataset.autoRefresh !== '1') {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const refreshUrl = monitor.dataset.refreshUrl;
    if (!csrfToken || !refreshUrl) {
        return;
    }

    const stateClasses = ['online', 'offline', 'unknown', 'maintenance'];
    let attempts = 0;

    const rowFor = (id) => Array.from(monitor.querySelectorAll('[data-monitor-game-server]'))
        .find((row) => row.dataset.monitorGameServer === String(id));

    const applyMonitor = (payload) => {
        payload.game_servers.forEach((server) => {
            const row = rowFor(server.id);
            if (!row) {
                return;
            }

            const state = server.availability_state || 'unknown';
            const stateElement = row.querySelector('[data-monitor-public-state]');
            const onlineElement = row.querySelector('[data-monitor-public-online]');
            const maintenanceMessageElement = row.querySelector('[data-monitor-maintenance-message]');

            if (stateElement) {
                stateElement.classList.remove(...stateClasses);
                stateElement.classList.add(state);
                stateElement.textContent = server.public_state_label;
            }

            if (onlineElement) {
                onlineElement.textContent = server.public_online_label;
                onlineElement.hidden = !payload.public_online_visible || state === 'maintenance';
            }

            if (maintenanceMessageElement) {
                maintenanceMessageElement.textContent = server.maintenance_message || '';
                maintenanceMessageElement.hidden = state !== 'maintenance' || !server.maintenance_message;
            }
        });
    };

    const refresh = async () => {
        attempts += 1;

        try {
            const response = await fetch(refreshUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                if ((response.status === 429 || response.status === 503) && attempts < 20) {
                    window.setTimeout(refresh, 1500);
                }

                return;
            }

            const payload = await response.json();
            if (payload.monitor) {
                applyMonitor(payload.monitor);
            }

            if (payload.refreshing && attempts < 20) {
                window.setTimeout(refresh, 1000);
            }
        } catch (_) {
            // Keep the last known status when an automatic refresh cannot run.
        }
    };

    refresh();
});
