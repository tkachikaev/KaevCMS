/*
 * Livewire dispatches bubbling CustomEvents after its DOM update.
 * Keep handlers in a same-origin asset to satisfy the CMS's script-src 'self' CSP.
 */
(() => {
    if (window.__kaevNewsCommentsEventsBound) return;
    window.__kaevNewsCommentsEventsBound = true;

    const focusEntry = (id) => {
        if (!Number.isSafeInteger(id) || id <= 0) return;
        requestAnimationFrame(() => requestAnimationFrame(() => {
            document.getElementById('news-comment-' + id)?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }));
    };

    document.addEventListener('news-comments:jump', (event) => {
        focusEntry(Number(event.detail?.commentId));
    });

    document.addEventListener('news-comments:reply', () => {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            const field = document.getElementById('news_comment_reply');
            field?.scrollIntoView({behavior: 'smooth', block: 'center'});
            field?.focus({preventScroll: true});
        }));
    });
})();
