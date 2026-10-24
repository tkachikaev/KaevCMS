import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

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

        // The overview art waits hidden until loaded, then eases in; blocks rise in order.
        await expect(page.locator('img[data-kc-reveal]:visible')).toHaveClass(/is-revealed/);
        await expect.poll(() => page.locator('img[data-kc-reveal]:visible').evaluate(image => getComputedStyle(image).opacity)).toBe('1');
        expect(await page.locator('.kc-overview > *').first().evaluate(block => getComputedStyle(block).animationName)).toBe('kc-rise');
        const coins = page.locator('img.account-wallet-coin-image');
        await expect(coins.first()).toHaveClass(/is-revealed/);
        await expect.poll(() => coins.evaluateAll(images => images.every(image => getComputedStyle(image).opacity === '1'))).toBe(true);

        // Menu items keep one weight and one line in every state, so nothing shifts on hover or click.
        const navLinks = page.locator('.account-nav a');
        expect(new Set(await navLinks.evaluateAll(links => links.map(link => getComputedStyle(link).fontWeight))).size).toBe(1);
        expect(await navLinks.evaluateAll(links => links.filter(link => link.offsetHeight !== links[0].offsetHeight).length)).toBe(0);
        await page.locator('.account-nav a[href*="/modules/daily-rewards"]').hover();
        expect(await page.locator('.account-nav a[href*="/modules/daily-rewards"]').evaluate(link => link.offsetHeight)).toBe(await navLinks.first().evaluate(link => link.offsetHeight));
        // The following background check covers selection, independently of normal hover.
        await page.mouse.move(1100, 80);

        // One highlight glides to the chosen item and survives the Livewire visit.
        const indicator = page.locator('.kc-nav-indicator');
        await expect(indicator).toHaveClass(/is-placed/);
        const glide = await page.evaluate(async () => {
            const positions = [];
            const sample = window.setInterval(() => positions.push(Math.round(new DOMMatrix(getComputedStyle(document.querySelector('.kc-nav-indicator')).transform).m42)), 16);
            document.querySelector('.account-nav a[href$="/account/web-inventory"]').click();
            await new Promise(resolve => window.setTimeout(resolve, 900));
            window.clearInterval(sample);

            return positions.filter((position, index) => index === 0 || position !== positions[index - 1]);
        });
        await expect(page).toHaveURL(/\/account\/web-inventory$/);
        const inventoryTop = await page.locator('.account-nav a[href$="/account/web-inventory"]').evaluate(link => link.offsetTop);
        expect(glide.length).toBeGreaterThan(3);
        expect(glide.at(-1)).toBe(inventoryTop);
        await expect(page.locator('.account-nav a.is-target')).toHaveCount(1);
        // After a real Livewire swap the persisted menu must still have just one background:
        // the moving indicator. Server/Livewire active classes must not paint a second one.
        await expect.poll(() => navLinks.evaluateAll(links => links.every(link => getComputedStyle(link).backgroundColor === 'rgba(0, 0, 0, 0)'))).toBe(true);
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
                const selection = nav.querySelector('.kc-nav-indicator').getBoundingClientRect();
                const track = nav.getBoundingClientRect();
                return nav.scrollWidth <= nav.clientWidth + 1 && [...nav.querySelectorAll('a')].every(link => {
                    const rect = link.getBoundingClientRect();
                    const text = link.querySelector('span').getBoundingClientRect();
                    return Math.abs(rect.left - track.left) < 1 && Math.abs(rect.right - track.right) < 1
                        && Math.abs(rect.width - selection.width) < 1 && text.right <= rect.right;
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
        await expect.poll(() => navLinks.evaluateAll(links => links.every(link => getComputedStyle(link).backgroundColor === 'rgba(0, 0, 0, 0)'))).toBe(true);
        await page.locator('.account-nav a[href$="/account"]').first().click();
        await expect(page).toHaveURL(/\/account$/);
        await expect(page.locator('img[data-kc-reveal]:visible')).toHaveClass(/is-revealed/);

        // Top-bar menus open with a short drop instead of appearing at once.
        for (const [menu, dropdown] of [['[data-account-wallet-menu]', '.account-profile-dropdown'], ['.account-language-menu', '.language-menu-list'], ['.account-profile-menu-topbar', '.account-profile-dropdown']]) {
            await page.locator(`${menu} > summary`).click();
            await expect(page.locator(menu)).toHaveAttribute('open', '');
            expect(await page.locator(`${menu} > ${dropdown}`).evaluate(element => getComputedStyle(element).animationName)).toBe('kc-pop');
            await page.locator(`${menu} > summary`).click();
        }

        const root = page.locator('html');
        const toggle = page.locator('[data-account-scheme-toggle]');
        await expect(root).toHaveAttribute('data-account-scheme', 'light');
        await expect(toggle).toHaveAttribute('aria-label', 'Включить тёмную тему');
        await expect(toggle).toHaveAttribute('role', 'switch');
        await expect(toggle).toHaveAttribute('aria-checked', 'false');
        const lightBackground = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);

        await toggle.click();
        await expect(root).toHaveAttribute('data-account-scheme', 'dark');
        await expect(toggle).toHaveAttribute('aria-label', 'Включить светлую тему');
        await expect(toggle).toHaveAttribute('aria-checked', 'true');
        expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).not.toBe(lightBackground);
        await expect(page.locator('.kc-banner-art-light')).toBeHidden();
        await expect(page.locator('.kc-banner-art-dark')).toBeVisible();
        await expect.poll(() => page.locator('.kc-banner-art-dark').evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);

        // Exercise the real browser snapshot animation, including duplicate activation.
        const revealAnimation = () => page.evaluate(() => document.getAnimations()
            .filter(animation => animation.effect?.pseudoElement === '::view-transition-new(root)')
            .map(animation => ({ frames: animation.effect.getKeyframes(), progress: animation.effect.getComputedTiming().progress })));
        await expect.poll(async () => (await revealAnimation()).length).toBe(1);
        const snapshot = (await revealAnimation())[0];
        expect(snapshot.frames[0].clipPath).toMatch(/^circle\(0px at /);
        expect(snapshot.frames.at(-1).clipPath).not.toBe(snapshot.frames[0].clipPath);
        await toggle.evaluate(button => button.click());
        await expect(root).toHaveAttribute('data-account-scheme', 'dark');
        await expect(root).not.toHaveClass(/kc-theme-reveal/);
        await expect(toggle).not.toHaveAttribute('aria-busy', 'true');

        // Space activation uses the switch centre rather than the top-left screen corner.
        const switchBounds = await toggle.boundingBox();
        await toggle.focus();
        await page.keyboard.press('Space');
        await expect(root).toHaveAttribute('data-account-scheme', 'light');
        await expect.poll(async () => (await revealAnimation()).length).toBe(1);
        const origin = (await revealAnimation())[0].frames[0].clipPath.match(/at ([\d.]+)px ([\d.]+)px/);
        expect(Number(origin[1])).toBeCloseTo(switchBounds.x + switchBounds.width / 2, 1);
        expect(Number(origin[2])).toBeCloseTo(switchBounds.y + switchBounds.height / 2, 1);
        await expect(root).not.toHaveClass(/kc-theme-reveal/);
        await page.keyboard.press('Space');
        await expect(root).toHaveAttribute('data-account-scheme', 'dark');

        // Livewire navigation keeps the document; a reload must restore the choice before paint.
        await page.locator('.account-nav a[href$="/account/wallet"]').click();
        await expect(page).toHaveURL(/\/account\/wallet$/);
        await expect(root).toHaveAttribute('data-account-scheme', 'dark');
        await expect(root).not.toHaveClass(/kc-theme-reveal/);
        await page.reload();
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
        // Inspect rendered pixels rather than duplicating the implementation's colour list.
        const particleColours = () => canvas.evaluate(element => {
            const pixels = element.getContext('2d').getImageData(0, 0, element.width, element.height).data;
            let warm = 0;
            let cool = 0;
            for (let offset = 0; offset < pixels.length; offset += 4) {
                if (pixels[offset + 3] < 12) continue;
                if (pixels[offset] > pixels[offset + 2] + 8) warm++;
                if (pixels[offset + 2] > pixels[offset] + 8) cool++;
            }
            return { warm, cool };
        });
        await expect.poll(async () => (await particleColours()).warm).toBeGreaterThan(0);
        await expect.poll(async () => (await particleColours()).cool).toBe(0);
        await page.locator('[data-account-scheme-toggle]').click();
        await expect.poll(async () => (await particleColours()).cool).toBeGreaterThan(0);
        await expect.poll(async () => (await particleColours()).warm).toBe(0);
        await page.reload();
        await expect(page.locator('html')).toHaveAttribute('data-account-scheme', 'dark');
        await expect.poll(async () => (await particleColours()).cool).toBeGreaterThan(0);
        await page.locator('[data-account-scheme-toggle]').click();
        await expect.poll(async () => (await particleColours()).warm).toBeGreaterThan(0);
        const firstFrame = await canvas.evaluate(element => element.toDataURL());
        await expect.poll(() => canvas.evaluate(element => element.toDataURL())).not.toBe(firstFrame);
        expect(await canvas.evaluate(element => {
            const c = element.getBoundingClientRect();
            const art = element.closest('.kc-banner-art').getBoundingClientRect();
            return Math.abs(c.left - art.left) < 1 && Math.abs(c.right - art.right) < 1
                && Math.abs(c.top - art.top) < 1 && Math.abs(c.bottom - art.bottom) < 1
                && getComputedStyle(element).pointerEvents === 'none';
        })).toBe(true);

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await expect.poll(hasPixels).toBe(false);
        await page.reload();
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

        await page.emulateMedia({ reducedMotion: 'reduce' });
        await expect(toggle).toBeDisabled();
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await expect.poll(hasPixels).toBe(false);
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
