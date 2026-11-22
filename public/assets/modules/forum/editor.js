(() => {
    "use strict";

    // The rich value starts disabled in HTML: without JavaScript only the
    // visible plain textarea can be submitted. Enable rich submission only
    // after the shared Tiptap editor has initialized successfully.
    document.querySelectorAll(".forum-editor[data-rich-editor]").forEach((root) => {
        const source = root.querySelector('textarea.rich-editor-source[name="body_html"]');
        const fallback = root.querySelector('.forum-editor-fallback textarea[name="body"]');
        if (!(source instanceof HTMLTextAreaElement) || !(fallback instanceof HTMLTextAreaElement)) {
            return;
        }

        const activate = () => {
            if (root.dataset.editorReady !== "1") {
                return;
            }

            source.disabled = false;
            fallback.disabled = true;
            observer.disconnect();
        };

        const observer = new MutationObserver(activate);
        observer.observe(root, { attributes: true, attributeFilter: ["data-editor-ready"] });
        activate();
    });
})();
