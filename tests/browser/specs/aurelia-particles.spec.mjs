import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';

const assets = resolve(import.meta.dirname, '../../../public/themes/kaev-aurelia/assets');
const hero = '<section class="hero"><canvas class="hero-particles" data-hero-particles aria-hidden="true"></canvas><div class="hero-content"><button type="button">Action</button></div></section>';

const initialize = async (page) => {
    await page.setContent(`<body class="kaev-aurelia"><main id="public-content">${hero}</main></body>`);
    await page.addStyleTag({ path: resolve(assets, 'css/app.css') });
    await page.addScriptTag({ path: resolve(assets, 'js/app.js') });
    await page.evaluate(() => new Promise(resolveFrame => requestAnimationFrame(() => requestAnimationFrame(resolveFrame))));
};

test('Aurelia particles animate behind usable hero controls and resize on mobile', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await initialize(page);
    const canvas = page.locator('[data-hero-particles]');
    await expect(canvas).toHaveCSS('pointer-events', 'none');
    await expect(canvas).toHaveAttribute('aria-hidden', 'true');
    const first = await canvas.evaluate(element => element.toDataURL());
    await expect.poll(() => canvas.evaluate(element => element.toDataURL())).not.toBe(first);
    await page.getByRole('button', { name: 'Action' }).click();
    await expect(page.getByRole('button', { name: 'Action' })).toBeFocused();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(() => canvas.evaluate(element => element.width)).toBe(390);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
    expect(errors).toEqual([]);
});

test('Aurelia particles respect live reduced-motion changes and high density displays', async ({ browser }) => {
    const context = await browser.newContext({ deviceScaleFactor: 3, reducedMotion: 'reduce' });
    try {
        const page = await context.newPage();
        await initialize(page);
        const canvas = page.locator('[data-hero-particles]');
        const dimensions = await canvas.evaluate(element => ({ bitmap: element.width, css: element.getBoundingClientRect().width }));
        expect(dimensions.bitmap).toBe(Math.round(dimensions.css * 2));
        const still = await canvas.evaluate(element => element.toDataURL());
        await page.waitForTimeout(150);
        expect(await canvas.evaluate(element => element.toDataURL())).toBe(still);
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await expect.poll(() => canvas.evaluate(element => element.toDataURL())).not.toBe(still);
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.evaluate(() => new Promise(resolveFrame => requestAnimationFrame(() => requestAnimationFrame(resolveFrame))));
        const stopped = await canvas.evaluate(element => element.toDataURL());
        await page.waitForTimeout(150);
        expect(await canvas.evaluate(element => element.toDataURL())).toBe(stopped);
    } finally {
        await context.close();
    }
});

test('Aurelia particles pause when hidden and clean up before SPA navigation', async ({ page }) => {
    await initialize(page);
    const canvas = page.locator('[data-hero-particles]');
    await page.evaluate(() => {
        const spacer = document.createElement('div');
        spacer.style.height = '2000px';
        document.body.append(spacer);
        window.scrollTo(0, 1200);
    });
    await expect(canvas).not.toBeInViewport();
    await page.evaluate(() => new Promise(resolveFrame => requestAnimationFrame(() => requestAnimationFrame(resolveFrame))));
    const outside = await canvas.evaluate(element => element.toDataURL());
    await page.waitForTimeout(150);
    expect(await canvas.evaluate(element => element.toDataURL())).toBe(outside);
    await page.evaluate(() => window.scrollTo(0, 0));
    await expect.poll(() => canvas.evaluate(element => element.toDataURL())).not.toBe(outside);
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, value: true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    const hidden = await canvas.evaluate(element => element.toDataURL());
    await page.waitForTimeout(150);
    expect(await canvas.evaluate(element => element.toDataURL())).toBe(hidden);
    await page.evaluate(() => {
        delete document.hidden;
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await expect.poll(() => canvas.evaluate(element => element.toDataURL())).not.toBe(hidden);
    await page.evaluate(() => document.dispatchEvent(new Event('livewire:navigating')));
    const stopped = await canvas.evaluate(element => element.toDataURL());
    await page.waitForTimeout(150);
    expect(await canvas.evaluate(element => element.toDataURL())).toBe(stopped);
    await page.evaluate(markup => {
        document.querySelector('#public-content').innerHTML = markup;
        document.dispatchEvent(new Event('livewire:navigated'));
    }, hero);
    const restarted = await canvas.evaluate(element => element.toDataURL());
    await expect.poll(() => canvas.evaluate(element => element.toDataURL())).not.toBe(restarted);
    await expect(canvas).toHaveCount(1);
});
