(() => {
    "use strict";

    // Topic page: quote or address a post in the reply form, and copy a link to a post.
    // Text is inserted through the shared rich editor when it is ready, otherwise into
    // the plain fallback textarea, so the page also works if the editor fails to load.

    const list = document.querySelector(".forum-post-list");
    const replyPanel = document.getElementById("reply");
    if (!list) {
        return;
    }

    const editorRoot = replyPanel?.querySelector(".forum-editor[data-rich-editor]") ?? null;
    const fallback = replyPanel?.querySelector('.forum-editor-fallback textarea[name="body"]') ?? null;

    const paragraph = (children) => {
        const p = document.createElement("p");
        children.forEach((child) => p.append(child));
        return p;
    };

    const insert = (html, plain) => {
        if (!replyPanel) {
            return;
        }

        if (editorRoot instanceof HTMLElement && editorRoot.dataset.editorReady === "1") {
            editorRoot.dispatchEvent(new CustomEvent("rich-editor:insert", { detail: { html } }));
        } else if (fallback instanceof HTMLTextAreaElement) {
            const separator = fallback.value.trim() === "" ? "" : "\n\n";
            fallback.value = fallback.value.replace(/\s+$/, "") + separator + plain;
            fallback.focus();
            fallback.setSelectionRange(fallback.value.length, fallback.value.length);
        }

        replyPanel.scrollIntoView({ behavior: "smooth", block: "center" });
    };

    const quote = (author, text) => {
        const wrote = list.dataset.forumWrote ?? "";
        const box = document.createElement("div");
        const blockquote = document.createElement("blockquote");
        const name = document.createElement("strong");
        name.textContent = author;
        blockquote.append(paragraph([name, document.createTextNode(` ${wrote}`)]));
        text.split(/\n{2,}/).map((part) => part.trim()).filter(Boolean).forEach((part) => {
            blockquote.append(paragraph([document.createTextNode(part)]));
        });
        box.append(blockquote, document.createElement("p"));

        const plain = [`${author} ${wrote}`, ...text.split("\n")].map((line) => `> ${line}`).join("\n") + "\n\n";
        insert(box.innerHTML, plain);
    };

    const mention = (author) => {
        const box = document.createElement("div");
        const name = document.createElement("strong");
        name.textContent = `@${author}`;
        box.append(paragraph([name, document.createTextNode(", ")]));
        insert(box.innerHTML, `@${author}, `);
    };

    const copyLink = async (link) => {
        const url = new URL(link.getAttribute("href") ?? "", window.location.href).href;
        try {
            await navigator.clipboard.writeText(url);
        } catch {
            return false;
        }

        const original = link.dataset.originalTitle ?? link.title;
        link.dataset.originalTitle = original;
        link.title = list.dataset.forumLinkCopied ?? original;
        link.classList.add("is-copied");
        window.setTimeout(() => {
            link.title = original;
            link.classList.remove("is-copied");
        }, 1600);
        history.replaceState(history.state, "", url);

        return true;
    };

    list.addEventListener("click", (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const quoteButton = target?.closest("[data-forum-quote]");
        if (quoteButton instanceof HTMLElement) {
            quote(quoteButton.dataset.author ?? "", quoteButton.dataset.text ?? "");
            return;
        }

        const mentionButton = target?.closest("[data-forum-mention]");
        if (mentionButton instanceof HTMLElement) {
            mention(mentionButton.dataset.author ?? "");
            return;
        }

        const link = target?.closest("[data-forum-post-link]");
        if (link instanceof HTMLAnchorElement && navigator.clipboard) {
            event.preventDefault();
            copyLink(link).then((copied) => {
                if (!copied) {
                    window.location.href = link.href;
                }
            });
        }
    });
})();
