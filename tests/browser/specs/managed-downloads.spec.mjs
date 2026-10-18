import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test('the whole download page is visually editable, responsive and recoverable without HTML', async ({ page, context }) => {
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
    const editorFor = locale => page.locator(`[data-locale-panel="${locale}"] [data-rich-editor]`);
    try {
        await gotoWithLocalNetworkRetry(page, '/admin/pages');
        await page.getByRole('link', { name: 'Редактировать: Скачать игру', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/pages\/\d+\/edit$/);
        const pageId = page.url().match(/pages\/(\d+)\/edit$/)[1];
        await expect(page.locator('input[name^="download_links"]')).toHaveCount(0);
        for (const locale of ['ru', 'en']) {
            await page.locator(`[data-locale-tab="${locale}"]`).click();
            const editor = editorFor(locale);
            await expect(editor).toHaveAttribute('data-editor-ready', '1');
            const cards = editor.locator('[data-content-block="card"]');
            await expect(cards).toHaveCount(2);
            await cards.first().locator('h2').fill(locale === 'ru' ? 'Наш клиент Interlude' : 'Our Interlude client');
            await cards.first().locator('p').first().fill(locale === 'ru' ? 'Описание, которое я изменил в редакторе.' : 'Description changed in the visual editor.');
            for (const [index, kind] of [[0, 'client'], [1, 'patch']]) {
                const link = cards.nth(index).locator('a');
                if (index === 0) await link.fill(locale === 'ru' ? 'Загрузить наш клиент' : 'Get our client');
                await link.click();
                await editor.locator('[data-editor-link]').click();
                await editor.locator('[data-editor-link-url]').fill(`https://files.example.test/${kind}.zip`);
                await expect(editor.locator('[data-editor-link-style]')).toHaveValue('primary');
                await editor.locator('[data-editor-link-apply]').click();
                await expect(link).toHaveAttribute('href', `https://files.example.test/${kind}.zip`);
            }
            // Card tools change the saved document, not a template outside the editor.
            await cards.first().locator('h2').click();
            await editor.locator('[data-editor-action="addCard"]').click();
            await expect(cards).toHaveCount(3);
            await cards.nth(1).locator('h2').click();
            await editor.locator('[data-editor-action="deleteCard"]').click();
            await expect(cards).toHaveCount(2);
        }
        await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/pages$/);
        await gotoWithLocalNetworkRetry(page, `/admin/pages/${pageId}/edit`);
        await expect(editorFor('ru').locator('h2').first()).toHaveText('Наш клиент Interlude');
        for (const theme of ['default', 'kaev-aurelia']) {
            await activate(theme);
            for (const [width, locale] of [[1440, 'ru'], [390, 'ru'], [390, 'en']]) {
                await visitor.setViewportSize({ width, height: 900 });
                await gotoWithLocalNetworkRetry(visitor, `/${locale}`);
                await visitor.locator('.hero-actions a[href$="/downloads"]').click();
                await expect(visitor).toHaveURL(new RegExp(`/${locale}/pages/downloads$`));
                const cards = visitor.locator('.cms-page [data-content-block="card"]');
                await expect(cards).toHaveCount(2);
                await expect(cards.first().locator('h2')).toHaveText(locale === 'ru' ? 'Наш клиент Interlude' : 'Our Interlude client');
                await expect(cards.first().getByRole('link')).toHaveAttribute('href', 'https://files.example.test/client.zip');
                await expect(cards.first().getByRole('link')).toHaveText(locale === 'ru' ? 'Загрузить наш клиент' : 'Get our client');
                await expect(cards.nth(1).getByRole('link')).toHaveAttribute('href', 'https://files.example.test/patch.zip');
                const boxes = await cards.evaluateAll(elements => elements.map(element => {
                    const box = element.getBoundingClientRect(); return { y: box.y, width: box.width };
                }));
                expect(Math.abs(boxes[0].width - boxes[1].width)).toBeLessThan(1);
                if (width < 680) expect(boxes[1].y).toBeGreaterThan(boxes[0].y);
                else expect(boxes[0].y).toBe(boxes[1].y);
                expect(await visitor.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
                expect(await cards.first().getByRole('link').evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(42);
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
        await expect(editorFor('ru').locator('[data-content-block="card"]')).toHaveCount(2);
        await page.getByRole('button', { name: 'Создать страницу', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/pages$/);
        await gotoWithLocalNetworkRetry(visitor, '/ru/downloads');
        await expect(visitor.locator('.cms-page a[aria-disabled="true"]')).toHaveCount(2);
        await gotoWithLocalNetworkRetry(page, '/admin/pages');
        await page.getByRole('link', { name: 'Редактировать: Скачать игру', exact: true }).click();
        const editor = editorFor('ru');
        const cards = editor.locator('[data-content-block="card"]');
        for (let i = 0; i < 2; i++) {
            await cards.first().locator('h2').click();
            await editor.locator('[data-editor-action="deleteCard"]').click();
        }
        await expect(cards).toHaveCount(0);
        await editor.locator('[data-editor-action="cards"]').click();
        await expect(cards).toHaveCount(2);
        for (let i = 0; i < 2; i++) {
            await cards.first().locator('h2').click();
            await editor.locator('[data-editor-action="deleteCard"]').click();
        }
        await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/pages$/);
        await gotoWithLocalNetworkRetry(visitor, '/ru/pages/downloads');
        await expect(visitor.locator('.cms-page [data-content-block="card"]')).toHaveCount(0);
        await expect(visitor.locator('.cms-page')).toContainText('Всё готово для начала приключения');
    } finally {
        await activate(originalTheme);
        await visitor.close();
    }
});
