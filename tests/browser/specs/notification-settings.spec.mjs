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

test('notification gear opens settings and existing switches save preferences', async ({ page }) => {
    await signIn(page);

    const center = page.locator('[data-admin-notification-center]');
    await center.locator('[data-admin-notification-menu] > summary').click();
    const settingsLink = center.locator('[data-testid="notification-settings-link"]');
    await expect(settingsLink).toBeVisible();
    await settingsLink.click();

    await expect(page).toHaveURL(/\/admin\/settings\/notifications$/);
    await expect(page.getByRole('heading', { name: 'Уведомления', exact: true })).toBeVisible();
    await expect(page.locator('.admin-toggle-row .admin-switch-control').first()).toBeVisible();
    const firstTooltip = page.locator('.field-help-tooltip').first();
    await expect(firstTooltip).toBeVisible();
    await firstTooltip.hover();
    await expect(firstTooltip.locator('[role="tooltip"]')).toBeVisible();

    const backgroundTasks = page.locator('#notification_background_tasks');
    await expect(backgroundTasks).toBeChecked();
    await backgroundTasks.uncheck();
    await page.locator('#notification_retention_days').selectOption('180');
    await page.getByRole('button', { name: 'Сохранить настройки', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/settings\/notifications$/);
    await expect(page.getByText('Настройки уведомлений сохранены.', { exact: true })).toBeVisible();
    await expect(backgroundTasks).not.toBeChecked();
    await expect(page.locator('#notification_retention_days')).toHaveValue('180');

    await backgroundTasks.check();
    await page.locator('#notification_retention_days').selectOption('90');
    await page.getByRole('button', { name: 'Сохранить настройки', exact: true }).click();
    await expect(backgroundTasks).toBeChecked();
});

test('notification switch exposes the full control as a clickable checkbox target', async ({ page }) => {
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/settings/notifications');

    const backgroundTasks = page.locator('#notification_background_tasks');
    await expect(backgroundTasks).toBeChecked();

    const inputBox = await backgroundTasks.boundingBox();
    expect(inputBox).not.toBeNull();
    expect(inputBox.width).toBeGreaterThanOrEqual(44);
    expect(inputBox.height).toBeGreaterThanOrEqual(24);

    await backgroundTasks.click();
    await expect(backgroundTasks).not.toBeChecked();
    await backgroundTasks.click();
    await expect(backgroundTasks).toBeChecked();
});

test('notification settings gear stays to the right of filters on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);

    const center = page.locator('[data-admin-notification-center]');
    await center.locator('[data-admin-notification-menu] > summary').click();
    const filters = center.locator('.admin-notification-filters');
    const settingsLink = center.locator('[data-testid="notification-settings-link"]');
    await expect(filters).toBeVisible();
    await expect(settingsLink).toBeVisible();

    const filterBox = await filters.boundingBox();
    const settingsBox = await settingsLink.boundingBox();
    expect(filterBox).not.toBeNull();
    expect(settingsBox).not.toBeNull();
    expect(settingsBox.x).toBeGreaterThan(filterBox.x + filterBox.width - 1);

    await settingsLink.click();
    await expect(page).toHaveURL(/\/admin\/settings\/notifications$/);
    const tooltip = page.locator('.field-help-tooltip').first();
    await tooltip.click();
    await expect(tooltip.locator('[role="tooltip"]')).toBeVisible();

    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));
    expect(widths.scroll).toBeLessThanOrEqual(widths.client + 1);
});
