(() => {
    "use strict";

    // Loaded in <head> without defer so the saved light/dark choice applies before the
    // first paint. The forum shares the public site's choice (kaevcms.site.scheme), so
    // switching here also switches the site, and the other way round.
    const root = document.documentElement;
    const storageKey = "kaevcms.site.scheme";
    const allowed = new Set(["light", "dark"]);
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

    try {
        const saved = window.localStorage.getItem(storageKey);
        if (allowed.has(saved)) {
            root.dataset.forumScheme = saved;
        }
    } catch {
        // Storage may be unavailable in private mode; keep the server default.
    }

    // The button names what a click does: "switch to the dark theme" while the page is light.
    const synchronize = () => {
        document.querySelectorAll("[data-forum-scheme-toggle]").forEach((button) => {
            const next = root.dataset.forumScheme === "dark" ? "light" : "dark";
            button.setAttribute("aria-checked", next === "light" ? "true" : "false");
            const label = button.dataset[next === "dark" ? "labelDark" : "labelLight"];
            if (label) {
                button.setAttribute("aria-label", label);
                button.setAttribute("title", label);
            }
        });
    };

    const select = (scheme) => {
        root.dataset.forumScheme = scheme;
        try {
            window.localStorage.setItem(storageKey, scheme);
        } catch {
            // The choice still applies to this visit.
        }
        synchronize();
    };

    let switching = false;
    const toggle = async (button) => {
        if (switching) {
            return;
        }
        const next = root.dataset.forumScheme === "dark" ? "light" : "dark";
        if (reducedMotion.matches || typeof document.startViewTransition !== "function" || typeof root.animate !== "function") {
            select(next);
            return;
        }

        // The same soft crossfade as the player account: the new look melts over the old.
        switching = true;
        button.setAttribute("aria-busy", "true");
        root.classList.add("forum-theme-reveal");
        try {
            const transition = document.startViewTransition(() => select(next));
            const finished = transition.finished.catch(() => {});
            await transition.ready;
            await root.animate({ opacity: [0, 1] }, {
                duration: 520,
                easing: "cubic-bezier(.4,0,.2,1)",
                pseudoElement: "::view-transition-new(root)",
            }).finished;
            await finished;
        } catch {
            if (root.dataset.forumScheme !== next) {
                select(next);
            }
        } finally {
            switching = false;
            root.classList.remove("forum-theme-reveal");
            button.removeAttribute("aria-busy");
        }
    };

    document.addEventListener("click", (event) => {
        const button = event.target instanceof Element ? event.target.closest("[data-forum-scheme-toggle]") : null;
        if (button instanceof HTMLButtonElement) {
            void toggle(button);
        }
    });
    document.addEventListener("DOMContentLoaded", synchronize);
})();
