(() => {
    if (window.__newsCommentsDeleteDialogBound) return;
    window.__newsCommentsDeleteDialogBound = true;

    document.addEventListener('click', (event) => {
        const open = event.target.closest('[data-news-comment-delete-open]');
        if (open) {
            const dialog = document.querySelector('[data-news-comment-delete-dialog]');
            if (!dialog || typeof dialog.showModal !== 'function') return;

            const form = dialog.querySelector('[data-news-comment-delete-form]');
            const copy = dialog.querySelector('[data-news-comment-delete-text]');
            const url = open.getAttribute('data-news-comment-delete-url');
            if (!form || !copy || !url) return;

            form.action = url;
            copy.textContent = open.getAttribute('data-news-comment-delete-referenced') === '1'
                ? copy.getAttribute('data-referenced-text')
                : copy.getAttribute('data-unreferenced-text');
            dialog.showModal();
            return;
        }

        const close = event.target.closest('[data-news-comment-delete-cancel]');
        if (close) {
            close.closest('[data-news-comment-delete-dialog]')?.close();
        }
    });
})();
