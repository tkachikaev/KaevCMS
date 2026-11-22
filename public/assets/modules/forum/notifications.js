(() => {
    "use strict";

    // The forum bell: refresh the unread number every two minutes while the tab is
    // visible, and ring once when it grows. Reading happens through normal links.
    const bell = document.querySelector("[data-forum-bell]");
    if (!(bell instanceof HTMLElement) || !bell.dataset.countUrl) {
        return;
    }

    const badge = bell.querySelector("[data-forum-bell-count]");
    let unread = Number(bell.dataset.unread || "0");

    const ring = () => {
        bell.classList.remove("is-ringing");
        void bell.offsetWidth;
        bell.classList.add("is-ringing");
    };

    const show = (count) => {
        if (!(badge instanceof HTMLElement)) {
            return;
        }
        badge.textContent = count > 99 ? "99+" : String(count);
        badge.hidden = count === 0;
        bell.classList.toggle("has-unread", count > 0);
    };

    const refresh = async () => {
        if (document.visibilityState !== "visible") {
            return;
        }
        try {
            const response = await fetch(bell.dataset.countUrl, {
                headers: { Accept: "application/json" },
                credentials: "same-origin",
                cache: "no-store",
            });
            if (!response.ok) {
                return;
            }
            const data = await response.json();
            const count = Number.isInteger(data?.unread) ? data.unread : 0;
            if (count > unread) {
                ring();
            }
            unread = count;
            show(count);
        } catch {
            // Offline or signed out: keep the last known number.
        }
    };

    if (unread > 0) {
        ring();
    }
    window.setInterval(refresh, 120000);
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            refresh();
        }
    });
})();
