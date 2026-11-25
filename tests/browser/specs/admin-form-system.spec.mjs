import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const email = process.env.PLAYWRIGHT_ADMIN_EMAIL || 'browser-admin@example.test';
const password = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'BrowserPassword123!';

const signIn = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await submitLogin({
        page,
        postPath: '/admin/login',
        label: 'Administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await expect(page).toHaveURL(/\/admin$/);
};

for (const appearance of ['light', 'dark']) {
    test(`editor link dialog preserves safe link editing (${appearance})`, async ({ page }) => {
        await page.setViewportSize(appearance === 'dark' ? { width: 390, height: 844 } : { width: 1280, height: 900 });
        await signIn(page);
        await gotoWithLocalNetworkRetry(page, '/admin/news/create');
        await page.evaluate((scheme) => window.KaevCMSAdminAppearance.apply(scheme), appearance);

        const editor = page.locator('[data-rich-editor]').first();
        const canvas = editor.locator('.ProseMirror');
        await canvas.fill('Server guide');
        await canvas.press('Control+A');
        await editor.locator('[data-editor-link]').click();
        const dialog = editor.locator('[data-editor-link-dialog]');
        const url = dialog.locator('[data-editor-link-url]');
        const apply = dialog.locator('[data-editor-link-apply]');
        await expect(dialog).toBeVisible();
        await expect(url).toBeFocused();

        const layout = await dialog.evaluate(element => {
            const bounds = element.getBoundingClientRect();
            return {
                left: bounds.left,
                right: bounds.right,
                viewport: innerWidth,
                overflow: element.scrollWidth - element.clientWidth,
            };
        });
        expect(layout.left).toBeGreaterThanOrEqual(-1);
        expect(layout.right).toBeLessThanOrEqual(layout.viewport + 1);
        expect(layout.overflow).toBeLessThanOrEqual(1);

        await url.fill('javascript:alert(1)');
        await apply.click();
        await expect(dialog).toBeVisible();
        await expect(editor.locator('.rich-editor-source')).not.toHaveValue(/javascript:/);
        await url.fill('https://example.com/guide');
        await apply.click();
        await expect(dialog).not.toBeVisible();
        await expect(canvas.locator('a')).toHaveAttribute('href', 'https://example.com/guide');

        await canvas.locator('a').click();
        await editor.locator('[data-editor-link]').click();
        await expect(url).toHaveValue('https://example.com/guide');
        await url.fill('https://example.com/cancelled');
        await dialog.locator('[data-editor-dialog-cancel]').click();
        await expect(dialog).not.toBeVisible();
        await expect(canvas.locator('a')).toHaveAttribute('href', 'https://example.com/guide');

        const imageDialog = editor.locator('[data-editor-image-dialog]');
        await imageDialog.evaluate(element => element.showModal());
        const imageOverflow = await imageDialog.evaluate(element => element.scrollWidth - element.clientWidth);
        expect(imageOverflow).toBeLessThanOrEqual(1);
        await imageDialog.locator('[data-editor-dialog-cancel]').click();
        await expect(imageDialog).not.toBeVisible();
    });
}

test('content publication controls fit the mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/pages/create');

    await expect(page.locator('.admin-toggle-row-compact .admin-switch-control').first()).toBeVisible();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});

test('user search and support settings remain accessible', async ({ page }) => {
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/users');
    const filter = page.locator('.admin-filter-bar').first();
    await expect(filter.locator('input[type="search"]')).toBeVisible();
    await expect(filter.getByRole('button', { name: 'Применить', exact: true })).toBeEnabled();

    await gotoWithLocalNetworkRetry(page, '/admin/extensions/support-tickets/settings');
    await expect(page.locator('#allow_editor_view')).toBeAttached();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});
