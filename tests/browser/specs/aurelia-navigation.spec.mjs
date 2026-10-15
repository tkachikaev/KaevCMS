import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';

const assets = resolve(import.meta.dirname, '../../../public/themes/kaev-aurelia/assets');
test.use({
    viewport: { width: 1280, height: 1600 },
    launchOptions: {
        ignoreDefaultArgs: ['--hide-scrollbars'],
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}),
    },
});

const initialize = async (page) => {
    await page.setContent(`<body class="kaev-aurelia">
        <header class="site-header" data-public-header><div class="container header-inner">
            <a class="brand" href="#home">KaevCMS</a>
            <nav class="main-nav"><a href="#home">Home</a><a href="#news">News</a></nav>
        </div></header>
        <main id="public-content" style="min-height:2400px">Page content</main>
    </body>`);
    await page.addStyleTag({ path: resolve(assets, 'css/app.css') });
    await page.addScriptTag({ path: resolve(assets, 'js/app.js') });
};

const headerGeometry = (page) => page.locator('[data-public-header]').evaluate(element => {
    const bounds = element.getBoundingClientRect();
    return {
        x: bounds.x, y: bounds.y, width: bounds.width, height: bounds.height,
        navigationX: element.querySelector('nav').getBoundingClientRect().x,
        opacity: getComputedStyle(element).opacity,
        shadow: getComputedStyle(element).boxShadow,
        background: getComputedStyle(element).backgroundColor,
    };
});

test('Aurelia header stays fixed when navigation removes the scrollbar', async ({ page }) => {
    await initialize(page);
    expect(await page.evaluate(() => document.documentElement.scrollHeight > innerHeight)).toBe(true);
    const before = await headerGeometry(page);
    await page.evaluate(() => document.dispatchEvent(new Event('livewire:navigate')));
    await expect(page.locator('#public-content')).toHaveCSS('opacity', '1');
    await expect(page.locator('#public-content')).toHaveCSS('transform', 'none');
    expect(await headerGeometry(page)).toEqual(before);
    await page.locator('#public-content').evaluate(element => { element.style.minHeight = '100px'; });
    expect(await page.evaluate(() => document.documentElement.scrollHeight > innerHeight)).toBe(false);
    expect(await headerGeometry(page)).toEqual(before);
    await expect(page.locator('[data-public-header]')).toHaveCSS('background-color', 'rgb(255, 253, 249)');
});

test('Aurelia fades in only new content and respects reduced motion', async ({ page }) => {
    await initialize(page);
    const entry = await page.evaluate(() => {
        document.dispatchEvent(new Event('livewire:navigating'));
        document.dispatchEvent(new Event('livewire:navigated'));
        const content = document.querySelector('#public-content');
        return {
            animations: content.getAnimations().length,
            transform: getComputedStyle(content).transform,
            headerAnimations: document.querySelector('[data-public-header]').getAnimations().length,
        };
    });
    expect(entry.animations).toBe(1);
    expect(entry.transform).toBe('none');
    expect(entry.headerAnimations).toBe(0);
    await expect.poll(() => page.locator('#public-content').evaluate(element => element.getAnimations().length)).toBe(0);
    await expect(page.locator('#public-content')).toHaveCSS('opacity', '1');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const reducedAnimations = await page.evaluate(() => {
        document.dispatchEvent(new Event('livewire:navigating'));
        document.dispatchEvent(new Event('livewire:navigated'));
        return document.querySelector('#public-content').getAnimations().length;
    });
    expect(reducedAnimations).toBe(0);
    await expect(page.locator('#public-content')).toHaveCSS('opacity', '1');
});
