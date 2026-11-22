(() => {
    "use strict";

    document.addEventListener("click", (event) => {
        const trigger = event.target.closest("[data-forum-dialog-open]");
        if (trigger) {
            const dialog = document.getElementById(trigger.dataset.forumDialogOpen);
            if (dialog instanceof HTMLDialogElement) {
                // A menu that opened the dialog should not stay open behind it.
                trigger.closest("details")?.removeAttribute("open");
                dialog.showModal();
            }
            return;
        }

        const close = event.target.closest("[data-dialog-close]");
        if (close) {
            close.closest("dialog")?.close();
        }
    });

    // Latest-activity tabs switch in place; the links still work without JavaScript.
    document.addEventListener("click", (event) => {
        const tab = event.target instanceof Element ? event.target.closest("[data-forum-activity-tab]") : null;
        if (!(tab instanceof HTMLAnchorElement) || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }
        const box = tab.closest("[data-forum-activity]");
        if (!box) {
            return;
        }
        event.preventDefault();
        box.querySelectorAll("[data-forum-activity-tab]").forEach((item) => {
            item.setAttribute("aria-selected", item === tab ? "true" : "false");
        });
        box.querySelectorAll("[data-forum-activity-panel]").forEach((panel) => {
            panel.hidden = panel.dataset.forumActivityPanel !== tab.dataset.forumActivityTab;
        });
    });

    document.addEventListener("change", (event) => {
        if (!event.target.matches("[data-forum-permissions] select[name=view_rule]")) {
            return;
        }

        const fields = event.target.closest("[data-forum-permissions]");
        if (event.target.value === "moderators") {
            for (const name of ["topic_rule", "reply_rule"]) {
                const select = fields.querySelector('select[name="' + name + '"]');
                if (select?.value === "registered") {
                    select.value = "moderators";
                }
            }
        }
    });
})();
