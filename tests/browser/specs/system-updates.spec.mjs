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

test('system updater keeps one compact red package warning beside upload', async ({ page }) => {
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/settings/system/updates');

    await expect(page.getByRole('heading', { name: 'Обновления системы', exact: true })).toBeVisible();
    await expect(page.getByText('Используйте пакеты обновлений только из доверенного источника.', { exact: true })).toHaveCount(0);
    await expect(page.locator('.update-trust-warning')).toHaveCount(0);

    const uploadCard = page.locator('.update-upload-card');
    const warning = uploadCard.locator('.update-upload-warning');
    await expect(warning).toHaveText('Загружайте только пакет из официального релиза KaevCMS. Архив обновления может заменять программные файлы KaevCMS. До изменения файлов пакет будет проверен. Ответственность за выбранный пакет несёт владелец сайта.');
    const warningColor = await warning.evaluate((element) => getComputedStyle(element).color);
    expect(warningColor).toMatch(/^rgb\((?:1[5-9]\d|2\d\d),\s*(?:0|[1-9]\d?),\s*(?:0|[1-9]\d?)\)$/);
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
