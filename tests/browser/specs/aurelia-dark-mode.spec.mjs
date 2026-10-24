import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const heroImage = (page) => page.locator('.aurelia-scene .hero-art').evaluate(element => getComputedStyle(element).backgroundImage);
const scheme = (page) => page.evaluate(() => document.documentElement.dataset.siteScheme);
const bodyBrightness = (page) => page.evaluate(() => getComputedStyle(document.body).backgroundColor.match(/\d+/g).slice(0, 3).reduce((sum, value) => sum + Number(value), 0));

const withAurelia = async (browser, run) => {
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();
    await gotoWithLocalNetworkRetry(admin, '/admin/login');
    await admin.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await admin.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({ page: admin, postPath: '/admin/login', label: 'Aurelia dark mode administrator login',
        submit: () => admin.getByRole('button', { name: 'Войти в панель' }).click() });
    await gotoWithLocalNetworkRetry(admin, '/admin/themes');
    const originalTheme = await admin.locator('.admin-overview-stat strong').first().innerText();
    const activate = async (theme) => {
        await gotoWithLocalNetworkRetry(admin, '/admin/themes');
        const form = admin.locator(`form[action$="/themes/${theme}/activate"]`);
        if (await form.count()) {
            await form.getByRole('button').click();
            await expect(form).toHaveCount(0);
        }
    };

    try {
        await activate('kaev-aurelia');
        await run();
    } finally {
        await activate(originalTheme);
        await adminContext.close();
    }
};

test('Aurelia switches to the night scheme, shows the dark portrait and remembers the choice', async ({ browser }) => {
    test.setTimeout(90_000);
    await withAurelia(browser, async () => {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await gotoWithLocalNetworkRetry(page, '/ru');
        const toggle = page.locator('.header-actions .scheme-toggle');

        // Starts light: the day portrait, a switch that offers the night scheme.
        expect(await scheme(page)).toBe('light');
        expect(await heroImage(page)).toContain('hero.webp');
        await expect(toggle).toHaveAttribute('aria-label', 'Включить тёмную тему');
        await expect(toggle).toHaveAttribute('aria-checked', 'false');
        const lightBrightness = await bodyBrightness(page);

        // The wave finishes and leaves nothing behind.
        await toggle.click();
        await expect(page.locator('html')).toHaveAttribute('data-site-scheme', 'dark');
        await expect(page.locator('html')).not.toHaveClass(/site-theme-reveal/, { timeout: 5000 });
        await expect(toggle).not.toHaveAttribute('aria-busy', 'true');
        expect(await heroImage(page)).toContain('hero-dark.webp');
        expect(await bodyBrightness(page)).toBeLessThan(lightBrightness / 4);
        await expect(toggle).toHaveAttribute('aria-label', 'Включить светлую тему');
        await expect(toggle).toHaveAttribute('aria-checked', 'true');
        expect(await page.evaluate(() => localStorage.getItem('kaevcms.site.scheme'))).toBe('dark');
        expect(await page.evaluate(() => document.querySelector('meta[name="theme-color"]').content)).toBe('#0d0c10');

        // The night scheme survives a Livewire visit without a reload, and a real reload.
        await page.evaluate(() => { window.__aureliaNoReload = true; });
        await page.locator('.main-nav a[href$="/news"]').click();
        await expect(page).toHaveURL(/\/news$/);
        expect(await page.evaluate(() => window.__aureliaNoReload === true)).toBe(true);
        expect(await scheme(page)).toBe('dark');
        expect(await bodyBrightness(page)).toBeLessThan(lightBrightness / 4);
        await page.reload();
        expect(await scheme(page)).toBe('dark');
        expect(await heroImage(page)).toContain('hero-dark.webp');

        // A language change reloads the page and still keeps the scheme.
        await page.locator('.header-actions .language-menu summary').click();
        await page.locator('.header-actions .language-menu a[hreflang="en"]').click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        expect(await scheme(page)).toBe('dark');
        await expect(page.locator('.header-actions .scheme-toggle')).toHaveAttribute('aria-label', 'Switch to the light theme');
        await page.locator('.header-actions .language-menu summary').click();
        await page.locator('.header-actions .language-menu a[hreflang="ru"]').click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'ru');

        // Back to day.
        await page.locator('.header-actions .scheme-toggle').click();
        await expect(page.locator('html')).toHaveAttribute('data-site-scheme', 'light');
        await expect(page.locator('html')).not.toHaveClass(/site-theme-reveal/, { timeout: 5000 });
        expect(await heroImage(page)).toContain('hero.webp');
        expect(await bodyBrightness(page)).toBe(lightBrightness);
        expect(errors).toEqual([]);
        await context.close();
    });
});

test('Aurelia night scheme keeps text readable on the public pages and fits every width', async ({ browser }) => {
    test.setTimeout(120_000);
    await withAurelia(browser, async () => {
        for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
            const context = await browser.newContext({ viewport });
            await context.addInitScript(() => { try { localStorage.setItem('kaevcms.site.scheme', 'dark'); } catch {} });
            const page = await context.newPage();
            for (const path of ['/ru', '/ru/news', '/ru/pages/about', '/ru/login', '/ru/register', '/ru/forgot-password', '/ru/statistics']) {
                await gotoWithLocalNetworkRetry(page, path);
                expect(await scheme(page)).toBe('dark');
                expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth), `${path} at ${viewport.width}px`).toBeLessThanOrEqual(1);

                // Plain text on solid backgrounds (panels, forms, footer) keeps WCAG AA contrast.
                const failures = await page.evaluate(() => {
                    const parse = (value) => {
                        const parts = (value.match(/rgba?\(([^)]+)\)/)?.[1] ?? '0,0,0,0').split(/[ ,/]+/).filter(Boolean).map(Number);
                        return [parts[0], parts[1], parts[2], parts.length > 3 ? parts[3] : 1];
                    };
                    const over = (top, below) => {
                        const alpha = top[3] + below[3] * (1 - top[3]);
                        return alpha === 0 ? [0, 0, 0, 0] : [0, 1, 2].map(i => (top[i] * top[3] + below[i] * below[3] * (1 - top[3])) / alpha).concat(alpha);
                    };
                    const luminance = ([r, g, b]) => [r, g, b].map((c) => { c /= 255; return c <= .03928 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4; })
                        .reduce((sum, value, index) => sum + value * [.2126, .7152, .0722][index], 0);
                    const found = [];
                    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
                    while (walker.nextNode()) {
                        const element = walker.currentNode.parentElement;
                        if (!walker.currentNode.textContent.trim() || !element) continue;
                        if (element.closest('.hero, .aurelia-scene, .page-hero, .auth-page, .main-nav, script, style, noscript')) continue;
                        const style = getComputedStyle(element);
                        const box = element.getBoundingClientRect();
                        if (style.visibility === 'hidden' || +style.opacity === 0 || !box.width || !box.height) continue;
                        let background = [0, 0, 0, 0];
                        let image = false;
                        for (let node = element; node; node = node.parentElement) {
                            if (getComputedStyle(node).backgroundImage !== 'none' && node !== document.body) image = true;
                        }
                        if (image) continue;
                        const chain = [];
                        for (let node = element; node; node = node.parentElement) chain.push(node);
                        for (const node of chain.reverse()) background = over(parse(getComputedStyle(node).backgroundColor), background);
                        background = over(background, [255, 255, 255, 1]);
                        const colour = over(parse(style.color), background);
                        const lighter = Math.max(luminance(colour), luminance(background));
                        const darker = Math.min(luminance(colour), luminance(background));
                        const size = parseFloat(style.fontSize);
                        const large = size >= 24 || (size >= 18.66 && Number(style.fontWeight) >= 700);
                        const ratio = (lighter + .05) / (darker + .05);
                        if (ratio < (large ? 3 : 4.5)) found.push(`${ratio.toFixed(2)} ${element.tagName.toLowerCase()} "${element.textContent.trim().slice(0, 30)}"`);
                    }
                    return found;
                });
                expect(failures, `${path} at ${viewport.width}px`).toEqual([]);
            }
            await context.close();
        }
    });
});

test('Aurelia language menu opens smoothly, and the phone menu carries its own scheme switch', async ({ browser }) => {
    test.setTimeout(90_000);
    await withAurelia(browser, async () => {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        await gotoWithLocalNetworkRetry(page, '/ru');
        const menu = page.locator('.header-actions .language-menu');
        await menu.locator('summary').click();
        const list = menu.locator('.language-menu-list');
        await expect(list).toBeVisible();
        expect(await list.evaluate(element => getComputedStyle(element).animationName)).toBe('aurelia-menu-pop');
        const delays = await list.locator('> a').evaluateAll(rows => rows.map(row => getComputedStyle(row).animationDelay));
        expect(delays.length).toBeGreaterThan(1);
        expect(new Set(delays).size).toBe(delays.length);
        // The panel is mid-fade shortly after opening, then fully shown: no sudden jump.
        const opacities = await page.evaluate(async () => {
            const panel = document.querySelector('.header-actions .language-menu-list');
            const details = panel.closest('details');
            details.open = false;
            const samples = [];
            const start = performance.now();
            details.open = true;
            await new Promise((resolve) => {
                const tick = () => {
                    samples.push(+getComputedStyle(panel).opacity);
                    if (performance.now() - start < 600) requestAnimationFrame(tick); else resolve();
                };
                requestAnimationFrame(tick);
            });
            return samples;
        });
        expect(opacities.some(value => value > 0.05 && value < 0.95)).toBe(true);
        expect(opacities.at(-1)).toBe(1);
        await page.keyboard.press('Escape');
        await context.close();

        // Reduced motion shows the list at once.
        const calm = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
        const calmPage = await calm.newPage();
        await gotoWithLocalNetworkRetry(calmPage, '/ru');
        await calmPage.locator('.header-actions .language-menu summary').click();
        expect(await calmPage.locator('.header-actions .language-menu-list').evaluate(element => getComputedStyle(element).animationName)).toBe('none');
        // Without the wave, the switch applies immediately.
        await calmPage.locator('.header-actions .scheme-toggle').click();
        await expect(calmPage.locator('html')).toHaveAttribute('data-site-scheme', 'dark');
        expect(await heroImage(calmPage)).toContain('hero-dark.webp');
        await calm.close();

        // Phone: the header switch is hidden, the menu offers its own, and the portrait is the dark one.
        const phoneContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const phone = await phoneContext.newPage();
        await gotoWithLocalNetworkRetry(phone, '/ru');
        await expect(phone.locator('.header-actions')).toBeHidden();
        await phone.locator('.menu-toggle').click();
        const phoneToggle = phone.locator('.main-nav .mobile-scheme-toggle');
        await expect(phoneToggle).toBeVisible();
        await expect(phoneToggle).toHaveText('Включить тёмную тему');
        await phoneToggle.click();
        await expect(phone.locator('html')).toHaveAttribute('data-site-scheme', 'dark');
        await expect(phone.locator('html')).not.toHaveClass(/site-theme-reveal/, { timeout: 5000 });
        await expect(phoneToggle).toHaveText('Включить светлую тему');
        expect(await heroImage(phone)).toContain('hero-dark.webp');
        expect(await phone.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
        await phoneContext.close();
    });
});

test('Aurelia eases the portrait and copy in after a reload instead of snapping them in', async ({ browser }) => {
    test.setTimeout(90_000);
    await withAurelia(browser, async () => {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        await context.addInitScript(() => { try { localStorage.setItem('kaevcms.site.scheme', 'dark'); } catch {} });
        // A slow portrait must keep the scene hidden until it is decoded, never show it half-drawn.
        await context.route('**/hero-dark.webp*', async (route) => {
            await new Promise(resolve => setTimeout(resolve, 700));
            await route.continue();
        });
        const page = await context.newPage();
        await page.goto('/ru', { waitUntil: 'commit' });
        await page.waitForSelector('.aurelia-scene .hero-art', { state: 'attached' });
        const timeline = await page.evaluate(() => new Promise((resolve) => {
            const art = document.querySelector('.aurelia-scene .hero-art');
            const title = document.querySelector('.hero h1');
            const start = performance.now();
            const rows = [];
            const tick = () => {
                rows.push({
                    time: performance.now() - start,
                    art: Number(getComputedStyle(art).opacity),
                    title: Number(getComputedStyle(title).opacity),
                    ready: document.documentElement.classList.contains('ka-ready'),
                });
                if (performance.now() - start < 3000) requestAnimationFrame(tick); else resolve(rows);
            };
            tick();
        }));
        const readyAt = timeline.find(row => row.ready).time;
        expect(readyAt).toBeGreaterThan(500);
        expect(timeline.filter(row => row.time < readyAt).every(row => row.art === 0 && row.title === 0)).toBe(true);
        // After the wait the portrait fades through intermediate steps, not in one jump.
        expect(timeline.filter(row => row.art > 0.05 && row.art < 0.95).length).toBeGreaterThan(5);
        expect(timeline.filter(row => row.title > 0.05 && row.title < 0.95).length).toBeGreaterThan(5);
        expect(timeline.at(-1).art).toBe(1);
        expect(timeline.at(-1).title).toBe(1);

        // Livewire visits keep the persisted scene and never replay the intro.
        await expect.poll(() => page.evaluate(() => document.documentElement.classList.contains('ka-intro')), { timeout: 8000 }).toBe(false);
        await page.locator('.main-nav a[href$="/news"]').click();
        await expect(page).toHaveURL(/\/news$/);
        expect(await page.evaluate(() => document.documentElement.classList.contains('ka-intro'))).toBe(false);
        expect(await page.locator('.aurelia-scene .hero-art').evaluate(element => getComputedStyle(element).opacity)).toBe('1');
        await context.close();
    });
});
