(() => {
    'use strict';

    const storageKey = 'kaevcms.account.banner-effects';
    let currentCanvas = null;
    let cleanup = null;

    const readPreference = () => {
        try {
            return window.localStorage.getItem(storageKey) !== 'off';
        } catch {
            return true;
        }
    };

    const initialize = (canvas) => {
        const art = canvas.closest('.kc-banner-art');
        const button = canvas.closest('.kc-banner')?.querySelector('[data-kc-effects-toggle]');
        let context;
        try {
            context = canvas.getContext('2d');
        } catch {
            return null;
        }
        if (!(art instanceof HTMLElement) || !(button instanceof HTMLButtonElement) || !context) {
            return null;
        }

        const controller = new AbortController();
        const { signal } = controller;
        const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        // Daytime gold follows Aurelia; the moonlit colours match the documentation artwork.
        const palettes = {
            light: ['255,215,0', '245,158,11', '255,244,204', '212,175,55'],
            dark: ['180,140,240', '211,184,255', '238,240,255'],
        };
        let enabled = readPreference();
        let disposed = false;
        let width = 0;
        let height = 0;
        let particles = [];
        let frame = null;
        let previousTime = null;
        const bounds = art.getBoundingClientRect();
        let inView = bounds.bottom > 0 && bounds.top < window.innerHeight;

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
            tone: Math.random(),
            glint: Math.random() > .82,
            spin: Math.random() * Math.PI,
            spinSpeed: (Math.random() - .5) * .02,
        });

        const clear = () => {
            // CSS sizes can be fractional, while the backing bitmap is rounded.
            // Clear every physical pixel, including a partially covered edge.
            context.save();
            context.setTransform(1, 0, 0, 1, 0, 0);
            context.clearRect(0, 0, canvas.width, canvas.height);
            context.restore();
        };
        const draw = (step) => {
            clear();
            const dark = document.documentElement.dataset.accountScheme === 'dark';
            const palette = dark ? palettes.dark : palettes.light;
            particles.forEach((particle) => {
                particle.y -= particle.speed * step;
                particle.sway += particle.swaySpeed * step;
                particle.pulse += particle.pulseSpeed * step;
                particle.spin += particle.spinSpeed * step;
                particle.x += Math.sin(particle.sway) * particle.amplitude * step;
                if (particle.y < -12 || particle.x < -16 || particle.x > width + 16) {
                    Object.assign(particle, createParticle(false));
                }
                const opacity = (.2 + .8 * (Math.sin(particle.pulse) + 1) / 2) * particle.opacity * .8;
                const color = palette[Math.floor(particle.tone * palette.length)];
                context.save();
                context.translate(particle.x, particle.y);
                context.fillStyle = `rgba(${color},${opacity})`;
                context.shadowColor = `rgba(${dark ? '183,145,245' : color},${opacity * .9})`;
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
        const canAnimate = () => !disposed && enabled && !motion.matches && !document.hidden
            && inView && width > 0 && height > 0;
        const tick = (time) => {
            frame = null;
            if (!canAnimate()) {
                // The media value can change before its change callback is delivered.
                // Keep the button and canvas in sync even in that interval.
                if (motion.matches) synchronize();
                return;
            }
            const step = previousTime === null ? 1 : Math.min((time - previousTime) / (1000 / 60), 2);
            previousTime = time;
            draw(step);
            frame = window.requestAnimationFrame(tick);
        };
        const synchronize = () => {
            stop();
            const active = enabled && !motion.matches;
            button.hidden = false;
            button.disabled = motion.matches;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.title = motion.matches ? button.dataset.labelReduced
                : active ? button.dataset.labelDisable : button.dataset.labelEnable;
            canvas.hidden = !active;
            if (!active) clear();
            if (canAnimate()) {
                draw(0);
                frame = window.requestAnimationFrame(tick);
            }
        };
        const resize = () => {
            const rectangle = art.getBoundingClientRect();
            width = rectangle.width;
            height = rectangle.height;
            const ratio = Math.min(window.devicePixelRatio || 1, 2);
            canvas.width = Math.round(width * ratio);
            canvas.height = Math.round(height * ratio);
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            // Keep Aurelia's particle appearance and desktop/mobile caps. The much smaller
            // banner needs a denser distribution for the motes to remain visible on pale art.
            const limit = window.innerWidth < 768 ? 40 : 95;
            const count = width > 0 && height > 0 ? Math.min(limit, Math.max(24, Math.floor(width * height / 1600))) : 0;
            particles = Array.from({ length: count }, () => createParticle(true));
            synchronize();
        };

        button.addEventListener('click', () => {
            enabled = !enabled;
            try {
                window.localStorage.setItem(storageKey, enabled ? 'on' : 'off');
            } catch {
                // The switch still works for this visit when storage is unavailable.
            }
            synchronize();
        }, { signal });
        document.addEventListener('visibilitychange', synchronize, { signal });
        // View-transition snapshots need the new palette before their next animation frame.
        document.addEventListener('kaev-classic:scheme-changed', synchronize, { signal });
        motion.addEventListener('change', synchronize, { signal });
        window.addEventListener('storage', (event) => {
            if (event.key === storageKey || event.key === null) {
                enabled = readPreference();
                synchronize();
            }
        }, { signal });
        const resizeObserver = new ResizeObserver(resize);
        const visibilityObserver = new IntersectionObserver(([entry]) => {
            inView = entry.isIntersecting;
            synchronize();
        });
        resize();
        resizeObserver.observe(art);
        visibilityObserver.observe(art);

        return () => {
            disposed = true;
            stop();
            controller.abort();
            resizeObserver.disconnect();
            visibilityObserver.disconnect();
        };
    };

    const dispose = () => {
        cleanup?.();
        cleanup = null;
        currentCanvas = null;
    };
    const mount = () => {
        const canvas = document.querySelector('[data-kc-banner-particles]');
        if (canvas === currentCanvas) return;
        dispose();
        if (canvas instanceof HTMLCanvasElement) {
            currentCanvas = canvas;
            cleanup = initialize(canvas);
        }
    };

    document.addEventListener('livewire:navigating', dispose);
    document.addEventListener('livewire:navigated', mount);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount, { once: true });
    } else {
        mount();
    }
})();
