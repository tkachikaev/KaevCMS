import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry, reloadWithLocalNetworkRetry } from '../support/navigation.mjs';

const adminEmail = process.env.PLAYWRIGHT_ADMIN_EMAIL || 'browser-admin@example.test';
const adminPassword = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'BrowserPassword123!';
const playerEmail = process.env.PLAYWRIGHT_PLAYER_EMAIL || 'browser-player@example.test';
const playerPassword = process.env.PLAYWRIGHT_PLAYER_PASSWORD || 'BrowserPlayerPassword123!';

const activateAccountTheme = async (page, slug) => {
    await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
    const form = page.locator(`form[action$="/account-themes/${slug}/activate"]`);
    if (await form.count()) {
        await form.getByRole('button').click();
        await expect(page.locator('.themes-toolbar .admin-overview-stat strong').first()).toHaveText(slug);
    }
};

test('Kaev Classic keeps the chosen scheme across navigation and reloads on desktop and phone', async ({ browser }) => {
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();
    await gotoWithLocalNetworkRetry(admin, '/admin/login');
    await admin.locator('#email').fill(adminEmail);
    await admin.locator('#password').fill(adminPassword);
    await submitLogin({
        page: admin, postPath: '/admin/login', label: 'Kaev Classic administrator login',
        submit: () => admin.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await gotoWithLocalNetworkRetry(admin, '/admin/account-themes');
    const previousTheme = (await admin.locator('.themes-toolbar .admin-overview-stat strong').first().innerText()).trim();

    try {
        await activateAccountTheme(admin, 'kaev-classic');

        const context = await browser.newContext();
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await gotoWithLocalNetworkRetry(page, '/login');
        await page.locator('#login').fill(playerEmail);
        await page.locator('#password').fill(playerPassword);
        await submitLogin({
            page, postPath: '/login', label: 'Kaev Classic player login',
            submit: () => page.locator('form').getByRole('button').click(),
        });
        await expect(page).toHaveURL(/\/account$/);

        // Navigation must work even when a Livewire response is slow.
        await page.route('**/account/web-inventory', async route => {
            await new Promise(resolve => setTimeout(resolve, 1100));
            await route.continue();
        });
        await page.locator('.account-nav a[href$="/account/web-inventory"]').click();
        await expect(page).toHaveURL(/\/account\/web-inventory$/, { timeout: 20000 });
        await page.unroute('**/account/web-inventory');
        await expect(page.locator('.account-nav a[aria-current="page"]')).toHaveCount(1);
        await expect(page.locator('.account-nav a[aria-current="page"]')).toHaveAttribute('href', /\/account\/web-inventory$/);

        // A slow/cancelled visit must not paint its destination as selected before it loads.
        let releaseNavigation;
        let requestStarted;
        const navigationGate = new Promise(resolve => { releaseNavigation = resolve; });
        const requestGate = new Promise(resolve => { requestStarted = resolve; });
        const holdPromoNavigation = async route => {
            requestStarted();
            await navigationGate;
            await route.continue();
        };
        await page.route('**/modules/promo-codes', holdPromoNavigation);
        const promoVisit = page.locator('.account-nav a[href$="/modules/promo-codes"]').click();
        try {
            await requestGate;
            await expect(page.locator('.account-nav a.is-target')).toHaveAttribute('href', /\/account\/web-inventory$/);
            await expect(page.locator('.account-nav a[aria-current="page"]')).toHaveAttribute('href', /\/account\/web-inventory$/);
        } finally {
            releaseNavigation();
            await promoVisit;
            await page.unroute('**/modules/promo-codes', holdPromoNavigation);
        }
        await expect(page).toHaveURL(/\/modules\/promo-codes$/);
        await expect(page.locator('.account-nav a[aria-current="page"]')).toHaveAttribute('href', /\/modules\/promo-codes$/);

        // The longest translated module label used to widen the implicit grid column:
        // hover filled the overflowing link while the selection stopped at the nav edge.
        await page.locator('.account-nav a[href$="/modules/support-tickets"]').click();
        await expect(page).toHaveURL(/\/modules\/support-tickets$/);
        await expect(page.locator('.account-nav')).not.toHaveClass(/kc-nav-moving/);
        const originalViewport = page.viewportSize();
        for (const width of [1440, 1100, 990, 390, 320]) {
            await page.setViewportSize({ width, height: 812 });
            await expect.poll(() => page.locator('.account-nav').evaluate(nav => {
                const track = nav.getBoundingClientRect();
                return nav.scrollWidth <= nav.clientWidth + 1 && [...nav.querySelectorAll('a')].every(link => {
                    const rect = link.getBoundingClientRect();
                    const text = link.querySelector('span').getBoundingClientRect();
                    return rect.left >= track.left - 1 && rect.right <= track.right + 1
                        && text.right <= rect.right + 1;
                });
            })).toBe(true);
        }
        await page.setViewportSize(originalViewport);

        // Interrupt the moving panel with another completed visit: it must settle on the
        // actual page without retaining the previous module's server-rendered active class.
        await page.locator('.account-nav a[href$="/account/characters"]').evaluate(link => link.click());
        await expect(page).toHaveURL(/\/account\/characters$/);
        await page.locator('.account-nav a[href$="/account/wallet"]').evaluate(link => link.click());
        await expect(page).toHaveURL(/\/account\/wallet$/);
        await expect(page.locator('.account-nav')).not.toHaveClass(/kc-nav-moving/);
        await expect(page.locator('.account-nav a[aria-current="page"]')).toHaveAttribute('href', /\/account\/wallet$/);
        await expect(page.locator('.account-nav a.is-target')).toHaveCount(1);
        await page.mouse.move(1100, 80);
        await page.locator('.account-nav a[href$="/account"]').first().click();
        await expect(page).toHaveURL(/\/account$/);

        // Top-bar menus must open and close.
        for (const [menu, dropdown] of [['[data-account-wallet-menu]', '.account-profile-dropdown'], ['.account-language-menu', '.language-menu-list'], ['.account-profile-menu-topbar', '.account-profile-dropdown']]) {
            await page.locator(`${menu} > summary`).click();
            await expect(page.locator(menu)).toHaveAttribute('open', '');
            await expect(page.locator(`${menu} > ${dropdown}`)).toBeVisible();
            await page.locator(`${menu} > summary`).click();
        }

        const root = page.locator('html');
        const toggle = page.locator('[data-account-scheme-toggle]');
        await expect(root).toHaveAttribute('data-account-scheme', 'light');
        await expect(toggle).toHaveAttribute('aria-label', 'Включить тёмную тему');
        await expect(toggle).toHaveAttribute('role', 'switch');
        await expect(toggle).toHaveAttribute('aria-checked', 'false');

        await toggle.click();
        await expect(root).toHaveAttribute('data-account-scheme', 'dark');
        await expect(toggle).toHaveAttribute('aria-label', 'Включить светлую тему');
        await expect(toggle).toHaveAttribute('aria-checked', 'true');
        await expect(page.locator('.kc-banner-art-light')).toBeHidden();
        await expect(page.locator('.kc-banner-art-dark')).toBeVisible();
        await expect.poll(() => page.locator('.kc-banner-art-dark').evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);

        // Wait until the control is ready for the next activation.
        await expect(root).not.toHaveClass(/kc-theme-reveal/);
        await expect(toggle).not.toHaveAttribute('aria-busy', 'true');

        // Keyboard activation switches the theme.
        await toggle.focus();
        await page.keyboard.press('Space');
        await expect(root).toHaveAttribute('data-account-scheme', 'light');
        await expect(root).not.toHaveClass(/kc-theme-reveal/);
        await page.keyboard.press('Space');
        await expect(root).toHaveAttribute('data-account-scheme', 'dark');

        // Livewire navigation keeps the document; a reload must restore the choice before paint.
        await page.locator('.account-nav a[href$="/account/wallet"]').click();
        await expect(page).toHaveURL(/\/account\/wallet$/);
        await expect(root).toHaveAttribute('data-account-scheme', 'dark');
        await expect(root).not.toHaveClass(/kc-theme-reveal/);
        await reloadWithLocalNetworkRetry(page);
        await expect(root).toHaveAttribute('data-account-scheme', 'dark');
        await expect(page.locator('[data-account-scheme-toggle]')).toHaveAttribute('aria-label', 'Включить светлую тему');
        await page.locator('.account-nav a[href$="/account"]').first().click();
        await expect(page).toHaveURL(/\/account$/);
        await expect(page.locator('.kc-banner-art-dark')).toBeVisible();
        await expect(page.locator('.kc-banner-art-light')).toBeHidden();
        await expect(page.locator('.kc-banner-art-dark')).toHaveClass(/is-revealed/);

        await page.setViewportSize({ width: 375, height: 812 });
        await page.locator('[data-account-sidebar-toggle]').click();
        await expect(page.locator('html')).toHaveClass(/account-sidebar-open/);
        await expect(page.locator('.account-nav a[href$="/account/characters"]')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);

        await page.locator('[data-account-scheme-toggle]').evaluate(button => button.click());
        await expect(page.locator('html')).toHaveAttribute('data-account-scheme', 'light');
        await expect(page.locator('.kc-banner-art-light')).toBeVisible();
        await expect(page.locator('.kc-banner-art-dark')).toBeHidden();

        // Players who turn motion off in the system see every block at once.
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.setViewportSize({ width: 1280, height: 800 });
        await gotoWithLocalNetworkRetry(page, '/account');
        expect(await page.locator('.kc-overview > *').first().evaluate(block => getComputedStyle(block).animationName)).toBe('none');
        expect(await page.locator('img[data-kc-reveal]:visible').evaluate(image => getComputedStyle(image).opacity)).toBe('1');
        await page.locator('[data-account-scheme-toggle]').click();
        await expect(root).toHaveAttribute('data-account-scheme', 'dark');
        await expect(root).not.toHaveClass(/kc-theme-reveal/);

        // Older browsers retain immediate switching without an overlay or a broken control.
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.evaluate(() => { document.startViewTransition = undefined; });
        await page.locator('[data-account-scheme-toggle]').click();
        await expect(root).toHaveAttribute('data-account-scheme', 'light');
        await expect(root).not.toHaveClass(/kc-theme-reveal/);
        await expect(page.locator('[data-account-scheme-toggle]')).toHaveAttribute('aria-checked', 'false');
        expect(errors).toEqual([]);
        await context.close();
    } finally {
        await activateAccountTheme(admin, previousTheme);
        await adminContext.close();
    }
});

test('Classic banner particles stay within the art, remember the switch and stop when unused', async ({ browser }) => {
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();
    await gotoWithLocalNetworkRetry(admin, '/admin/login');
    await admin.locator('#email').fill(adminEmail);
    await admin.locator('#password').fill(adminPassword);
    await submitLogin({
        page: admin, postPath: '/admin/login', label: 'Classic banner administrator login',
        submit: () => admin.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await gotoWithLocalNetworkRetry(admin, '/admin/account-themes');
    const previousTheme = (await admin.locator('.themes-toolbar .admin-overview-stat strong').first().innerText()).trim();
    const context = await browser.newContext();
    // Count actual canvas drawing, including detached canvases, without exposing runtime
    // counters in the product. This detects leaked render loops after SPA navigation.
    await context.addInitScript(() => {
        window.classicCanvasDraws = new Map();
        // Media callbacks can be delayed independently of the media value. The active
        // animation must still stop and synchronize its button on the next frame.
        const addMediaListener = MediaQueryList.prototype.addEventListener;
        MediaQueryList.prototype.addEventListener = function (type, listener, options) {
            if (type === 'change' && this.media === '(prefers-reduced-motion: reduce)' && typeof listener === 'function') {
                return addMediaListener.call(this, type, event => {
                    if (!window.delayClassicMotionCallbacks) listener.call(this, event);
                }, options);
            }
            return addMediaListener.call(this, type, listener, options);
        };
        const clear = CanvasRenderingContext2D.prototype.clearRect;
        CanvasRenderingContext2D.prototype.clearRect = function (...args) {
            if (this.canvas.matches('[data-kc-banner-particles]')) {
                window.classicCanvasDraws.set(this.canvas, (window.classicCanvasDraws.get(this.canvas) || 0) + 1);
            }
            return clear.apply(this, args);
        };
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
        await activateAccountTheme(admin, 'kaev-classic');
        await gotoWithLocalNetworkRetry(page, '/login');
        await page.locator('#login').fill(playerEmail);
        await page.locator('#password').fill(playerPassword);
        await submitLogin({
            page, postPath: '/login', label: 'Classic banner player login',
            submit: () => page.locator('form').getByRole('button').click(),
        });
        await expect(page).toHaveURL(/\/account$/);
        const canvas = page.locator('[data-kc-banner-particles]');
        const toggle = page.getByRole('button', { name: 'Эффекты на баннере', exact: true });
        await expect(toggle).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        const hasPixels = () => canvas.evaluate(element => element.getContext('2d')
            .getImageData(0, 0, element.width, element.height).data.some((value, index) => index % 4 === 3 && value > 0));
        await expect.poll(hasPixels).toBe(true);
        await page.locator('[data-account-scheme-toggle]').click();
        await expect(page.locator('html')).toHaveAttribute('data-account-scheme', 'dark');
        await expect.poll(hasPixels).toBe(true);
        await reloadWithLocalNetworkRetry(page);
        await expect(page.locator('html')).toHaveAttribute('data-account-scheme', 'dark');
        await expect.poll(hasPixels).toBe(true);
        await page.locator('[data-account-scheme-toggle]').click();
        await expect.poll(hasPixels).toBe(true);
        const firstFrame = await canvas.evaluate(element => element.toDataURL());
        await expect.poll(() => canvas.evaluate(element => element.toDataURL())).not.toBe(firstFrame);
        await expect(canvas).toHaveCSS('pointer-events', 'none');

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await expect.poll(hasPixels).toBe(false);
        await reloadWithLocalNetworkRetry(page);
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await page.locator('.account-nav a[href$="/account/wallet"]').click();
        await expect(page).toHaveURL(/\/account\/wallet$/);
        await expect(canvas).toHaveCount(0);
        await page.locator('.account-nav a[href$="/account"]').first().click();
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await toggle.click();
        await expect.poll(hasPixels).toBe(true);

        await page.evaluate(() => { window.retiredClassicCanvas = document.querySelector('[data-kc-banner-particles]'); });
        await page.locator('.account-nav a[href$="/account/wallet"]').click();
        await expect(page).toHaveURL(/\/account\/wallet$/);
        const stoppedAt = await page.evaluate(() => window.classicCanvasDraws.get(window.retiredClassicCanvas));
        await page.waitForTimeout(220);
        expect(await page.evaluate(() => window.classicCanvasDraws.get(window.retiredClassicCanvas))).toBe(stoppedAt);
        await page.locator('.account-nav a[href$="/account"]').first().click();
        await expect.poll(hasPixels).toBe(true);

        await page.evaluate(() => { window.delayClassicMotionCallbacks = true; });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await expect(toggle).toBeDisabled();
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await expect.poll(hasPixels).toBe(false);
        await page.evaluate(() => { window.delayClassicMotionCallbacks = false; });
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await expect(toggle).toBeEnabled();
        await expect.poll(hasPixels).toBe(true);

        await page.setViewportSize({ width: 390, height: 650 });
        await page.evaluate(() => scrollTo(0, document.documentElement.scrollHeight));
        await expect.poll(() => canvas.evaluate(element => element.getBoundingClientRect().bottom < 0)).toBe(true);
        // Let IntersectionObserver deliver its visibility update, then verify no drawing occurs.
        await page.waitForTimeout(200);
        const pausedAt = await canvas.evaluate(element => window.classicCanvasDraws.get(element));
        await page.waitForTimeout(220);
        expect(await canvas.evaluate(element => window.classicCanvasDraws.get(element))).toBe(pausedAt);
        await page.evaluate(() => scrollTo(0, 0));
        await expect.poll(() => canvas.evaluate(element => window.classicCanvasDraws.get(element))).toBeGreaterThan(pausedAt);
        const target = await toggle.boundingBox();
        expect(target.x).toBeGreaterThanOrEqual(0);
        expect(target.x + target.width).toBeLessThanOrEqual(390);
        expect(errors).toEqual([]);
    } finally {
        await context.close();
        await activateAccountTheme(admin, previousTheme);
        await adminContext.close();
    }
});
