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

test('system updater displays a package trust warning before upload', async ({ page }) => {
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/settings/system/updates');

    await expect(page.getByRole('heading', { name: 'Обновления системы', exact: true })).toBeVisible();

    const uploadCard = page.locator('.update-upload-card');
    const warning = uploadCard.locator('.update-upload-warning');
    await expect(warning).toBeVisible();
    await expect(warning).toContainText('официального релиза KaevCMS');
    await expect(page.locator('#update_package')).toBeVisible();
});

test('system updater has no horizontal overflow on a mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/settings/system/updates');

    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client + 1);
});

test('system updater uploads a package larger than the PHP limit in parts', async ({ page }) => {
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/settings/system/updates');

    const form = page.locator('form[data-update-chunked-upload]');
    const chunkBytes = Number(await form.getAttribute('data-update-chunk-bytes'));
    const parts = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().endsWith('/admin/settings/system/updates/chunks')) parts.push(request.url());
    });

    // Not a real package: it must reach package inspection (and be rejected there), not fail with HTTP 413.
    await page.locator('#update_package').setInputFiles({
        name: 'larger-than-upload-limit.zip',
        mimeType: 'application/zip',
        buffer: Buffer.alloc(chunkBytes * 2 + 1024, 1),
    });
    await form.getByRole('button', { name: 'Загрузить и проверить' }).click();

    await expect(page).toHaveURL(/\/admin\/settings\/system\/updates$/, { timeout: 30000 });
    // Assembled from its parts and rejected by the package inspector, not by the upload.
    await expect(page.locator('.update-upload-form .admin-field-error')).toContainText('Пакет обновления отклонён: Загруженный файл не является доступным для чтения ZIP-архивом.');
    await expect(page.getByText('Content Too Large', { exact: false })).toHaveCount(0);
    expect(parts).toHaveLength(3);
});
