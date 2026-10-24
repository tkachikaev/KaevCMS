(() => {
    'use strict';

    const navigationClass = 'public-is-navigating';
    const readyAttribute = 'data-aurelia-ready';
    let monitorCleanup = null;
    let particlesCleanup = null;
    let particlesCanvas = null;
    let pageInitialized = false;
    let initializedContent = null;
    let pageEntryAnimation = null;
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

    const initializeHeroParticles = () => {
        const canvas = document.querySelector('[data-hero-particles]');
        const hero = canvas?.closest('.aurelia-scene-frame, .hero');
        const scene = canvas?.closest('[data-aurelia-scene]');
        const context = canvas?.getContext('2d');
        if (!hero || !context) {
            return () => {};
        }

        const controller = new AbortController();
        const { signal } = controller;
        const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const palettes = {
            light: ['255,215,0', '245,158,11', '255,244,204', '212,175,55'],
            dark: ['205,189,247', '170,142,240', '236,228,255', '150,120,230'],
        };
        let width = 0;
        let height = 0;
        let particles = [];
        let frame = null;
        let previousTime = null;
        let disposed = false;
        const initialBounds = hero.getBoundingClientRect();
        let inView = initialBounds.bottom > 0 && initialBounds.top < window.innerHeight;
        const mouse = { x: 0, y: 0, active: false };

        const createParticle = (initial) => ({
            x: Math.random() * width,
            y: initial ? Math.random() * height : height + 10,
            size: .8 + Math.random() * 2.2,
            speed: .25 + Math.random() * .45,
            sway: Math.random() * Math.PI * 2,
            swaySpeed: .008 + Math.random() * .02,
            amplitude: .4 + Math.random() * 1.2,
            pulse: Math.random() * Math.PI * 2,
            pulseSpeed: .01 + Math.random() * .03,
            opacity: .25 + Math.random() * .65,
            color: Math.floor(Math.random() * palettes.light.length),
            glint: Math.random() > .82,
            spin: Math.random() * Math.PI,
            spinSpeed: (Math.random() - .5) * .02,
        });

        const draw = (step) => {
            context.clearRect(0, 0, width, height);
            const colors = document.documentElement.dataset.siteScheme === 'dark' ? palettes.dark : palettes.light;
            particles.forEach((particle) => {
                if (step > 0) {
                    particle.y -= particle.speed * step;
                    particle.sway += particle.swaySpeed * step;
                    particle.pulse += particle.pulseSpeed * step;
                    particle.spin += particle.spinSpeed * step;
                    particle.x += Math.sin(particle.sway) * particle.amplitude * step;
                    if (mouse.active) {
                        const dx = particle.x - mouse.x;
                        const dy = particle.y - mouse.y;
                        const distance = Math.hypot(dx, dy);
                        if (distance > 1 && distance < 180) {
                            const force = (1 - distance / 180) * 1.5 * step;
                            particle.x += dx / distance * force * 1.8;
                            particle.y -= force * .8;
                        }
                    }
                    if (particle.y < -15 || particle.x < -30 || particle.x > width + 30) {
                        Object.assign(particle, createParticle(false));
                    }
                }
                const opacity = (.2 + .8 * (Math.sin(particle.pulse) + 1) / 2) * particle.opacity;
                context.save();
                context.translate(particle.x, particle.y);
                const color = colors[particle.color];
                context.fillStyle = `rgba(${color},${opacity})`;
                context.shadowColor = `rgba(${color},${opacity * .9})`;
                context.shadowBlur = particle.size * 5;
                context.beginPath();
                if (particle.glint) {
                    context.rotate(particle.spin);
                    const radius = particle.size * 2.8;
                    context.moveTo(0, -radius);
                    context.quadraticCurveTo(0, 0, radius, 0);
                    context.quadraticCurveTo(0, 0, 0, radius);
                    context.quadraticCurveTo(0, 0, -radius, 0);
                    context.quadraticCurveTo(0, 0, 0, -radius);
                } else {
                    context.arc(0, 0, particle.size, 0, Math.PI * 2);
                }
                context.fill();
                context.restore();
            });
        };

        const stop = () => {
            if (frame !== null) window.cancelAnimationFrame(frame);
            frame = null;
            previousTime = null;
        };
        const tick = (time) => {
            frame = null;
            if (disposed || motion.matches || document.hidden || !inView || (scene && scene.dataset.mode !== 'home')) return;
            const step = previousTime === null ? 1 : Math.min((time - previousTime) / (1000 / 60), 2);
            previousTime = time;
            draw(step);
            frame = window.requestAnimationFrame(tick);
        };
        const sync = () => {
            stop();
            if (disposed || document.hidden || !inView || (scene && scene.dataset.mode !== 'home')) return;
            draw(0);
            if (!motion.matches) frame = window.requestAnimationFrame(tick);
        };
        const resize = () => {
            const bounds = hero.getBoundingClientRect();
            width = bounds.width;
            height = bounds.height;
            const ratio = Math.min(window.devicePixelRatio || 1, 2);
            canvas.width = Math.round(width * ratio);
            canvas.height = Math.round(height * ratio);
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            const limit = width < 768 ? 40 : 95;
            particles = Array.from({ length: Math.min(limit, Math.floor(width * height / 12000)) }, () => createParticle(true));
            sync();
        };

        // The stage is replaced during navigation, while the scene is persisted.
        // Bind its pointer tracking to the document so it survives a round trip.
        const pointerSurface = scene ? document : hero;
        pointerSurface.addEventListener('pointermove', (event) => {
            if (event.pointerType !== 'mouse') return;
            const bounds = hero.getBoundingClientRect();
            mouse.x = event.clientX - bounds.left;
            mouse.y = event.clientY - bounds.top;
            mouse.active = (!scene || scene.dataset.mode === 'home')
                && mouse.x >= 0 && mouse.x <= bounds.width && mouse.y >= 0 && mouse.y <= bounds.height;
        }, { signal });
        pointerSurface.addEventListener('pointerleave', () => { mouse.active = false; }, { signal });
        document.addEventListener('visibilitychange', sync, { signal });
        motion.addEventListener('change', sync, { signal });
        const resizeObserver = new ResizeObserver(resize);
        const visibilityObserver = new IntersectionObserver(([entry]) => {
            inView = entry.isIntersecting;
            sync();
        });
        resize();
        resizeObserver.observe(hero);
        visibilityObserver.observe(hero);
        const modeObserver = new MutationObserver(sync);
        if (scene) modeObserver.observe(scene, { attributes: true, attributeFilter: ['data-mode'] });

        return () => {
            disposed = true;
            stop();
            controller.abort();
            resizeObserver.disconnect();
            visibilityObserver.disconnect();
            modeObserver.disconnect();
        };
    };

    const initializePage = () => {
        const content = document.querySelector('#public-content');
        const scene = document.querySelector('[data-aurelia-scene]');
        const heading = content?.querySelector('.hero, .page-hero');
        if (scene) {
            const mode = heading?.classList.contains('hero') ? 'home' : heading ? 'page' : 'hidden';
            // Keep the artwork and veil outside the animated content. In particular,
            // page-to-page navigation must never replay an opacity transition.
            if (scene.dataset.mode !== mode) scene.dataset.mode = mode;
        }
        // DOMContentLoaded and Livewire's initial navigated event describe the
        // same document. Never fade already-painted content back to transparent.
        if (content && content === initializedContent) {
            content.removeAttribute('aria-busy');
            document.documentElement.classList.remove(navigationClass);
            return;
        }
        monitorCleanup?.();
        monitorCleanup = initializeServerMonitor();
        const canvas = document.querySelector('[data-hero-particles]');
        if (canvas !== particlesCanvas) {
            particlesCleanup?.();
            particlesCleanup = initializeHeroParticles();
            particlesCanvas = canvas;
        }
        initializeHeader();

        content?.removeAttribute('aria-busy');
        pageEntryAnimation?.cancel();
        pageEntryAnimation = null;
        if (pageInitialized && content && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            pageEntryAnimation = content.animate([{ opacity: 0 }, { opacity: 1 }], {
                duration: 180,
                easing: 'ease-out',
            });
        } else if (!pageInitialized && content && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            // A document entry is deliberately subtle: never blank the screen
            // while changing layouts or replay this on Livewire's bootstrap event.
            document.body.animate([{ opacity: .88 }, { opacity: 1 }], {
                duration: 160,
                easing: 'ease-out',
            });
        }
        pageInitialized = true;
        initializedContent = content;

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
        initializedContent = null;
        monitorCleanup?.();
        monitorCleanup = null;
        if (!particlesCanvas?.closest('[data-aurelia-scene]')) {
            particlesCleanup?.();
            particlesCleanup = null;
            particlesCanvas = null;
        }
        pageEntryAnimation?.cancel();
        pageEntryAnimation = null;
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
