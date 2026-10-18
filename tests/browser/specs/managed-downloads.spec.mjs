import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test('download page is editable, responsive in both public themes and recoverable after deletion', async ({ page, context }) => {
    test.setTimeout(60_000);
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({ page, postPath: '/admin/login', label: 'Download page administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click() });
    await gotoWithLocalNetworkRetry(page, '/admin/themes');
    const originalTheme = await page.locator('.admin-overview-stat strong').first().innerText();
    const activate = async theme => {
        await gotoWithLocalNetworkRetry(page, '/admin/themes');
        const form = page.locator(`form[action$="/themes/${theme}/activate"]`);
        if (await form.count()) {
            await form.getByRole('button').click();
            await expect(form).toHaveCount(0);
        }
    };
    const visitor = await context.newPage();
    let externalRequests = 0;
    await visitor.route('https://files.example.test/**', route => { externalRequests++; return route.abort(); });
    try {
        await gotoWithLocalNetworkRetry(page, '/admin/pages');
        await page.getByRole('link', { name: 'Редактировать: Скачать игру', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/pages\/\d+\/edit$/);
        const pageId = page.url().match(/pages\/(\d+)\/edit$/)[1];
        await expect(page.locator('[data-download-links-editor]')).toBeVisible();
        await page.locator('#download_client_url').fill('https://files.example.test/client.zip');
        await page.locator('#download_patch_url').fill('https://files.example.test/patch.zip');
        await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/pages$/);
        for (const theme of ['default', 'kaev-aurelia']) {
            await activate(theme);
            for (const [width, locale] of [[1440, 'ru'], [390, 'ru'], [390, 'en']]) {
                await visitor.setViewportSize({ width, height: 900 });
                await gotoWithLocalNetworkRetry(visitor, `/${locale}`);
                await visitor.locator('.hero-actions a[href$="/downloads"]').click();
                await expect(visitor).toHaveURL(new RegExp(`/${locale}/pages/downloads$`));
                const client = visitor.locator('[data-download-file="download_client_url"]');
                const patch = visitor.locator('[data-download-file="download_patch_url"]');
                await expect(client.getByRole('link')).toHaveAttribute('href', 'https://files.example.test/client.zip');
                await expect(patch.getByRole('link')).toHaveAttribute('href', 'https://files.example.test/patch.zip');
                await expect(visitor.locator('link[href*="assets/css/downloads.css"]')).toHaveCount(1);
                const cards = await visitor.locator('.download-file').evaluateAll(elements => elements.map(element => {
                    const box = element.getBoundingClientRect(); return { x: box.x, y: box.y, width: box.width };
                }));
                expect(cards).toHaveLength(2);
                expect(Math.abs(cards[0].width - cards[1].width)).toBeLessThan(1);
                if (width < 680) expect(cards[1].y).toBeGreaterThan(cards[0].y);
                else expect(cards[0].y).toBe(cards[1].y);
                expect(await visitor.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
                await expect(client.getByRole('link')).toBeVisible();
                expect(await client.getByRole('link').evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(42);
            }
        }
        expect(externalRequests).toBe(0);
        await gotoWithLocalNetworkRetry(page, '/admin/pages');
        await page.locator(`[data-page-delete-url$="/pages/${pageId}"]`).click();
        await page.locator('[data-page-delete-dialog] button[type="submit"]').click();
        await expect(page.locator(`[data-page-delete-url$="/pages/${pageId}"]`)).toHaveCount(0);
        await gotoWithLocalNetworkRetry(visitor, '/ru/downloads');
        await expect(visitor.getByRole('heading', { name: 'Файлы пока недоступны' })).toBeVisible();
        await gotoWithLocalNetworkRetry(page, '/admin/pages');
        await page.getByRole('link', { name: 'Создать страницу скачивания', exact: true }).click();
        await expect(page.locator('#page_slug_ru')).toHaveValue('downloads');
        await expect(page.locator('#download_client_url')).toHaveValue('');
        await page.getByRole('button', { name: 'Создать страницу', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/pages$/);
        await gotoWithLocalNetworkRetry(visitor, '/ru/downloads');
        await expect(visitor.locator('.download-file button:disabled')).toHaveCount(2);
    } finally {
        await activate(originalTheme);
        await visitor.close();
    }
});
