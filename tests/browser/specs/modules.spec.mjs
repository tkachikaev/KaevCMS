import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const signIn = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({
        page,
        postPath: '/admin/login',
        label: 'Module catalogue administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
};

test('module cards open their section and confirm before disabling', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/modules');

    const card = page.locator('[data-module-id="daily-rewards"]');
    await expect(card.getByRole('link', { name: 'Открыть', exact: true })).toHaveAttribute('href', /\/admin\/extensions\/daily-rewards$/);

    // Cancelling keeps the module enabled.
    const dialog = page.locator('[data-module-disable-dialog]');
    await card.getByRole('button', { name: 'Отключить', exact: true }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'Отключить модуль?' })).toBeVisible();
    await expect(dialog.locator('[data-module-disable-title]')).toHaveText('Ежедневные награды');
    await dialog.getByRole('button', { name: 'Отмена', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(card).toHaveClass(/active/);

    // Confirming disables it; then it is enabled again for the other specs.
    await card.getByRole('button', { name: 'Отключить', exact: true }).click();
    await dialog.getByRole('button', { name: 'Да, отключить', exact: true }).click();
    await expect(page.getByText('Модуль Ежедневные награды отключён', { exact: false })).toBeVisible();
    await expect(card).not.toHaveClass(/active/);
    await expect(card.getByRole('link', { name: 'Открыть', exact: true })).toHaveCount(0);

    await card.getByRole('button', { name: 'Включить', exact: true }).click();
    await expect(card).toHaveClass(/active/);
});
