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
