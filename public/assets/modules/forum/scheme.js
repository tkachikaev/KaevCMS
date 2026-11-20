(() => {
    'use strict';

    // The forum shares the public site's saved appearance, without importing
    // the full Aurelia animation bundle or a second theme preference.
    const root = document.documentElement;
    if (root.dataset.forumTheme !== 'kaev-aurelia') {
        return;
    }

    try {
        const saved = window.localStorage.getItem('kaevcms.site.scheme');
        if (saved === 'light' || saved === 'dark') {
            root.dataset.forumScheme = saved;
        }
    } catch {
        // Storage may be unavailable in private mode; keep the server default.
    }
})();
