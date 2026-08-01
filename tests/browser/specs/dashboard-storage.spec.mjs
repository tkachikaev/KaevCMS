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

const expectNoHorizontalOverflow = async (page) => {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client + 1);
};

test('dashboard shows disk usage bar and database storage details', async ({ page }) => {
    await signIn(page);

    const storage = page.getByTestId('dashboard-storage-card');
    await expect(storage).toBeVisible();
    await expect(storage.getByRole('heading', { name: 'Хранилище', exact: true })).toBeVisible();

    const disk = storage.getByTestId('dashboard-disk-storage');
    const progress = disk.getByRole('progressbar', { name: 'Использование диска сервера' });
    await expect(progress).toBeVisible();
    await expect(progress).toHaveAttribute('aria-valuemin', '0');
    await expect(progress).toHaveAttribute('aria-valuemax', '100');
    await expect(progress).toHaveAttribute('aria-valuenow', /^\d+(?:\.\d+)?$/);

    const renderedUsage = await progress.evaluate((element) => {
        const expected = Number.parseFloat(element.getAttribute('aria-valuenow') || '0');
        const value = Number.parseFloat(element.getAttribute('value') || '0');
        const maximum = Number.parseFloat(element.getAttribute('max') || '100');

        return {
            expected,
            ratio: maximum > 0 ? (value / maximum) * 100 : 0,
        };
    });

    expect(renderedUsage.ratio).toBeGreaterThan(0);
    expect(Math.abs(renderedUsage.ratio - renderedUsage.expected)).toBeLessThanOrEqual(1);
    await expect(progress).toHaveAttribute('value', /^\d+(?:\.\d+)?$/);
    await expect(progress).toHaveAttribute('max', '100');
    await expect(disk).toContainText('Свободно:');

    const database = storage.getByTestId('dashboard-database-storage');
    await expect(database).toContainText('База данных KaevCMS');
    await expect(database).toContainText(/SQLite|MySQL|MariaDB/);
    await expect(database).toContainText('Общий размер');
    await expect(database).toContainText('Данные');
    await expect(database).toContainText('Индексы');
    await expect(database).toContainText('Таблицы');
});

test('dashboard storage remains readable without horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);

    const storage = page.getByTestId('dashboard-storage-card');
    await expect(storage).toBeVisible();
    await expect(storage.getByTestId('dashboard-disk-storage')).toBeVisible();
    await expect(storage.getByTestId('dashboard-database-storage')).toBeVisible();
    await expectNoHorizontalOverflow(page);

    await page.setViewportSize({ width: 768, height: 900 });
    await expectNoHorizontalOverflow(page);
});


test('dashboard uses compact refresh controls and keeps servers to the right of storage', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signIn(page);

    await expect(page.getByText('Всего онлайн', { exact: true })).toHaveCount(0);
    const toolbar = page.getByTestId('dashboard-monitor-toolbar');
    await expect(toolbar).toBeVisible();
    await expect(toolbar.getByRole('button', { name: 'Проверить сейчас' })).toBeVisible();

    const storageBox = await page.getByTestId('dashboard-storage-card').boundingBox();
    const gameBox = await page.getByTestId('dashboard-game-servers-card').boundingBox();
    const loginBox = await page.getByTestId('dashboard-login-servers-card').boundingBox();
    const toolbarBox = await toolbar.boundingBox();

    expect(storageBox).not.toBeNull();
    expect(gameBox).not.toBeNull();
    expect(loginBox).not.toBeNull();
    expect(toolbarBox).not.toBeNull();
    expect(storageBox.x).toBeLessThan(gameBox.x);
    expect(gameBox.y).toBeLessThan(loginBox.y);
    expect(toolbarBox.height).toBeLessThanOrEqual(60);
});

test('dashboard fills the left column with the player overview card', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signIn(page);

    const storage = page.getByTestId('dashboard-storage-card');
    const players = page.getByTestId('dashboard-players-card');
    const gameServers = page.getByTestId('dashboard-game-servers-card');

    await expect(players).toBeVisible();
    await expect(players.getByRole('heading', { name: 'Игроки', exact: true })).toBeVisible();
    await expect(players).toContainText('Зарегистрировано');
    await expect(players).toContainText('Игровые аккаунты');
    await expect(players).toContainText('Персонажи');

    const storageBox = await storage.boundingBox();
    const playersBox = await players.boundingBox();
    const gameServersBox = await gameServers.boundingBox();

    expect(storageBox).not.toBeNull();
    expect(playersBox).not.toBeNull();
    expect(gameServersBox).not.toBeNull();
    expect(Math.abs(playersBox.x - storageBox.x)).toBeLessThanOrEqual(1);
    expect(playersBox.y).toBeGreaterThan(storageBox.y + storageBox.height - 1);
    expect(playersBox.x).toBeLessThan(gameServersBox.x);
});
