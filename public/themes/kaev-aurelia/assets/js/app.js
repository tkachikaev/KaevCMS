(() => {
    'use strict';

    const navigationClass = 'public-is-navigating';
    const readyAttribute = 'data-aurelia-ready';
    let monitorCleanup = null;
    let statisticsAbortController = null;

    const isPlainPrimaryClick = (event) => event.button === 0
        && !event.metaKey
        && !event.ctrlKey
        && !event.shiftKey
        && !event.altKey;

    const statisticsPage = () => document.querySelector('[data-statistics-page]');

    const setStatisticsBusy = (page, busy) => {
        if (!page) {
            return;
        }

        page.classList.toggle('is-updating', busy);
        page.setAttribute('aria-busy', busy ? 'true' : 'false');
        page.querySelector('[data-statistics-progress]')?.setAttribute('aria-hidden', busy ? 'false' : 'true');
    };

    const loadStatisticsFragment = async (href) => {
        const currentPage = statisticsPage();
        if (!currentPage) {
            window.location.assign(href);
            return;
        }

        const targetUrl = new URL(href, window.location.href);
        if (targetUrl.origin !== window.location.origin) {
            window.location.assign(targetUrl.href);
            return;
        }

        if (targetUrl.href === window.location.href) {
            return;
        }

        statisticsAbortController?.abort();
        const controller = new AbortController();
        statisticsAbortController = controller;
        setStatisticsBusy(currentPage, true);

        try {
            const response = await fetch(targetUrl.href, {
                method: 'GET',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`Statistics request failed with status ${response.status}.`);
            }

            const html = await response.text();
            const parsedDocument = new DOMParser().parseFromString(html, 'text/html');
            const nextPage = parsedDocument.querySelector('[data-statistics-page]');

            if (!nextPage) {
                throw new Error('Statistics fragment is missing in the response.');
            }

            const activePage = statisticsPage();
            if (!activePage || controller.signal.aborted) {
                return;
            }

            activePage.innerHTML = nextPage.innerHTML;
            setStatisticsBusy(activePage, false);
            activePage.classList.add('is-refreshed');
            window.setTimeout(() => activePage.classList.remove('is-refreshed'), 220);

            if (parsedDocument.title) {
                document.title = parsedDocument.title;
            }

            const finalUrl = response.url || targetUrl.href;
            window.history.replaceState(window.history.state, '', finalUrl);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }

            window.location.assign(targetUrl.href);
        } finally {
            if (statisticsAbortController === controller) {
                statisticsAbortController = null;
                setStatisticsBusy(statisticsPage(), false);
            }
        }
    };

    const closeMenu = () => {
        const menu = document.querySelector('[data-main-menu]');
        const toggle = document.querySelector('[data-menu-toggle]');

        menu?.classList.remove('open');
        toggle?.setAttribute('aria-expanded', 'false');
        document.documentElement.classList.remove('public-menu-open');
    };

    const initializeHeader = () => {
        const header = document.querySelector('[data-public-header]');
        const toggle = header?.querySelector('[data-menu-toggle]');
        const menu = header?.querySelector('[data-main-menu]');

        if (!header || !toggle || !menu || header.hasAttribute(readyAttribute)) {
            return;
        }

        header.setAttribute(readyAttribute, '');

        toggle.addEventListener('click', () => {
            const willOpen = !menu.classList.contains('open');
            menu.classList.toggle('open', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            document.documentElement.classList.toggle('public-menu-open', willOpen);
        });

        menu.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('a')) {
                closeMenu();
            }
        });
    };

    const initializeServerMonitor = () => {
        const monitor = document.querySelector('[data-server-monitor]');
        if (!monitor || monitor.dataset.autoRefresh !== '1') {
            return () => {};
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const refreshUrl = monitor.dataset.refreshUrl;
        if (!csrfToken || !refreshUrl) {
            return () => {};
        }

        const stateClasses = ['online', 'offline', 'unknown', 'maintenance'];
        const abortController = new AbortController();
        let timeoutId = null;
        let attempts = 0;
        let disposed = false;

        const numberFormatter = new Intl.NumberFormat(document.documentElement.lang || undefined);
        const rowFor = (id) => Array.from(monitor.querySelectorAll('[data-monitor-game-server]'))
            .find((row) => row.dataset.monitorGameServer === String(id));

        const applyMonitor = (payload) => {
            if (!payload || !Array.isArray(payload.game_servers)) {
                return;
            }

            payload.game_servers.forEach((server) => {
                const row = rowFor(server.id);
                if (!row) {
                    return;
                }

                const state = server.availability_state || 'unknown';
                const stateElement = row.querySelector('[data-monitor-public-state]');
                const onlineElement = row.querySelector('[data-monitor-public-online]');
                const maintenanceMessageElement = row.querySelector('[data-monitor-maintenance-message]');
                const onlineCell = row.querySelector('[data-monitor-online-cell]');
                const statusDot = row.querySelector('.status-dot');

                if (statusDot) {
                    statusDot.classList.remove(...stateClasses);
                    statusDot.classList.add(state);
                }

                if (stateElement) {
                    stateElement.classList.remove(...stateClasses);
                    stateElement.classList.add(state);
                    stateElement.textContent = server.public_state_label;
                }

                if (onlineElement) {
                    const players = Number(server.public_players);
                    onlineElement.textContent = server.public_players !== null && Number.isFinite(players)
                        ? numberFormatter.format(players)
                        : (server.public_online_label || '—');
                }

                if (onlineCell) {
                    onlineCell.hidden = !payload.public_online_visible || state === 'maintenance';
                }

                if (maintenanceMessageElement) {
                    maintenanceMessageElement.textContent = server.maintenance_message || '';
                    maintenanceMessageElement.hidden = state !== 'maintenance' || !server.maintenance_message;
                }
            });
        };

        const schedule = (callback, delay) => {
            if (!disposed) {
                timeoutId = window.setTimeout(callback, delay);
            }
        };

        const refresh = async () => {
            attempts += 1;

            try {
                const response = await fetch(refreshUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: abortController.signal,
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
                applyMonitor(payload.monitor);

                if (payload.refreshing && attempts < 20) {
                    schedule(refresh, 1000);
                }
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    window.console?.debug?.('Kaev Aurelia server monitor refresh was skipped.', error);
                }
            }
        };

        refresh();

        return () => {
            disposed = true;
            abortController.abort();
            if (timeoutId !== null) {
                window.clearTimeout(timeoutId);
            }
        };
    };

    const initializePage = () => {
        monitorCleanup?.();
        monitorCleanup = initializeServerMonitor();
        initializeHeader();

        const content = document.querySelector('#public-content');
        content?.removeAttribute('aria-busy');

        window.requestAnimationFrame(() => {
            document.documentElement.classList.remove(navigationClass);
        });
    };

    const beginNavigation = () => {
        closeMenu();
        document.documentElement.classList.add(navigationClass);
        document.querySelector('#public-content')?.setAttribute('aria-busy', 'true');
    };

    const cleanupPage = () => {
        monitorCleanup?.();
        monitorCleanup = null;
    };

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element) || !isPlainPrimaryClick(event) || event.defaultPrevented) {
            return;
        }

        const link = event.target.closest('a[data-statistics-link]');
        if (!link || link.target || link.hasAttribute('download')) {
            return;
        }

        event.preventDefault();
        loadStatisticsFragment(link.href);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });

    document.addEventListener('pointerdown', (event) => {
        if (!document.documentElement.classList.contains('public-menu-open')) {
            return;
        }

        if (event.target instanceof Element && !event.target.closest('[data-public-header]')) {
            closeMenu();
        }
    });

    document.addEventListener('livewire:navigate', beginNavigation);
    document.addEventListener('livewire:navigating', cleanupPage);
    document.addEventListener('livewire:navigated', initializePage);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializePage, { once: true });
    } else {
        initializePage();
    }
})();
