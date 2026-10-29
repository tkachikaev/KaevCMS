(() => {
    'use strict';
    const link = document.querySelector('[data-social-continue]');
    if (!(link instanceof HTMLAnchorElement)) return;
    const target = new URL(link.href, location.href);
    const origins = ['https://accounts.google.com', 'https://oauth.yandex.ru', 'https://id.vk.ru', 'https://oauth.telegram.org', 'https://discord.com', 'https://www.facebook.com'];
    if (target.protocol !== 'https:' || !origins.includes(target.origin)) return;
    // The server has validated the authenticated session and generated the provider URL.
    window.location.replace(target.href);
})();
