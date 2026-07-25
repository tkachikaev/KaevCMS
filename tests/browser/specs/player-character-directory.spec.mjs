import { expect, test } from '@playwright/test';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const email = process.env.PLAYWRIGHT_PLAYER_EMAIL || 'browser-player@example.test';
const password = process.env.PLAYWRIGHT_PLAYER_PASSWORD || 'BrowserPlayerPassword123!';

const signIn = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/login');
    await page.locator('#login').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('form').getByRole('button').click();
    await expect(page).toHaveURL(/\/account$/);
};

test('player character display mode persists after reload', async ({ page }) => {
    await signIn(page);

    await page.locator('.account-nav').getByRole('link', { name: 'Персонажи' }).click();
    await expect(page).toHaveURL(/\/account\/characters$/);

    const grouped = page.getByRole('tab', { name: 'По серверам' });
    const all = page.getByRole('tab', { name: 'Все персонажи' });

    await expect(all).toHaveAttribute('aria-selected', 'true');
    await grouped.click();
    await expect(grouped).toHaveAttribute('aria-selected', 'true');
    await all.click();
    await expect(all).toHaveAttribute('aria-selected', 'true');

    await page.reload();
    await expect(all).toHaveAttribute('aria-selected', 'true');
});

test('player shell persists during account navigation and browser history', async ({ page }) => {
    await signIn(page);

    const sidebar = page.locator('[data-account-sidebar]');
    const topbar = page.locator('[data-account-topbar]');
    await sidebar.evaluate((element) => {
        element.dataset.persistenceProbe = 'sidebar-kept';
    });
    await topbar.evaluate((element) => {
        element.dataset.persistenceProbe = 'topbar-kept';
    });

    await page.locator('.account-nav').getByRole('link', { name: 'Игровые аккаунты' }).click();
    await expect(page).toHaveURL(/\/account\/game-accounts$/);
    await expect(sidebar).toHaveAttribute('data-persistence-probe', 'sidebar-kept');
    await expect(topbar).toHaveAttribute('data-persistence-probe', 'topbar-kept');

    await page.getByRole('link', { name: /Подробнее|View details/ }).first().click();
    await expect(page).toHaveURL(/\/account\/game-accounts\/\d+$/);
    await expect(sidebar).toHaveAttribute('data-persistence-probe', 'sidebar-kept');
    await expect(topbar).toHaveAttribute('data-persistence-probe', 'topbar-kept');

    await page.goBack();
    await expect(page).toHaveURL(/\/account\/game-accounts$/);
    await expect(sidebar).toHaveAttribute('data-persistence-probe', 'sidebar-kept');

    await page.goBack();
    await expect(page).toHaveURL(/\/account$/);
    await expect(topbar).toHaveAttribute('data-persistence-probe', 'topbar-kept');
});

test('luxury player theme remains reactive after SPA navigation', async ({ page }) => {
    await signIn(page);

    await expect(page.locator('link[href*="account-themes/luxury/assets/css/app.css"]')).toHaveCount(1);
    await expect(page.locator('.account-hero')).toBeVisible();
    await expect(page.locator('.account-future-balance')).toContainText(/Монеты|Coins/);

    await page.locator('.account-nav').getByRole('link', { name: 'Игровые аккаунты' }).click();
    await expect(page).toHaveURL(/\/account\/game-accounts$/);
    await expect(page.locator('.game-account-card').first()).toBeVisible();

    await page.locator('.account-nav').getByRole('link', { name: 'Обзор' }).click();
    await expect(page).toHaveURL(/\/account$/);
    await expect(page.locator('.account-character-directory')).toHaveCount(0);

    await page.locator('.account-nav').getByRole('link', { name: 'Персонажи' }).click();
    await expect(page).toHaveURL(/\/account\/characters$/);
    const allCharacters = page.getByRole('tab', { name: 'Все персонажи' });
    await allCharacters.click();
    await expect(allCharacters).toHaveAttribute('aria-selected', 'true');
});

test('player can choose an administrator-provided account avatar in a modal', async ({ page }) => {
    await signIn(page);

    await page.locator('.account-user-avatar-button').click();
    const dialog = page.locator('[data-avatar-modal]');
    await expect(dialog).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Выбор аватара', exact: true })).toBeVisible();

    await page.locator('label:has(input[name="avatar_filename"][value="browser-avatar.png"])').click();
    await page.getByRole('button', { name: 'Сохранить аватар', exact: true }).click();

    await expect(page).toHaveURL(/\/account$/);
    await expect(page.getByText('Аватар сохранён.', { exact: true })).toBeVisible();
    await expect(page.locator('.account-profile-avatar img[src*="/uploads/account-avatars/browser-avatar.png"]')).toBeVisible();
});

test('player web inventory is available from the persistent account shell', async ({ page }) => {
    await signIn(page);

    await page.locator('.account-nav').getByRole('link', { name: 'Веб-инвентарь' }).click();

    await expect(page).toHaveURL(/\/account\/web-inventory$/);
    await expect(page.getByRole('heading', { name: 'Веб-инвентарь', exact: true })).toBeVisible();
    await expect(page.getByText('Ваш веб-инвентарь пуст')).toBeVisible();
    await expect(page.locator('[data-account-sidebar]')).toBeVisible();
    await expect(page.locator('[data-account-topbar]')).toBeVisible();
});

test('player activates a promo code into the server-bound web inventory', async ({ page }) => {
    await signIn(page);

    await page.locator('.account-nav').getByRole('link', { name: 'Промокоды' }).click();
    await expect(page).toHaveURL(/\/modules\/promo-codes$/);
    await page.locator('input[name="code"]').fill('browser2026');
    await page.getByRole('button', { name: 'Активировать код', exact: true }).click();

    await expect(page).toHaveURL(/\/modules\/promo-codes$/);
    await expect(page.getByText('Награды добавлены в веб-инвентарь сервера Browser World.')).toBeVisible();
    await expect(page.getByText('BROWSER2026', { exact: true })).toBeVisible();
    await expect(page.getByText(/Адена × 1 000 000/)).toBeVisible();

    await page.getByRole('link', { name: 'Открыть веб-инвентарь', exact: true }).click();
    await expect(page).toHaveURL(/\/account\/web-inventory$/);
    await expect(page.getByText('Адена', { exact: true })).toBeVisible();
    await expect(page.getByText('1 000 000')).toBeVisible();
});

test('aurelia player theme keeps rounded surfaces and active module navigation after SPA changes', async ({ page, context }) => {
    const adminEmail = process.env.PLAYWRIGHT_ADMIN_EMAIL || 'browser-admin@example.test';
    const adminPassword = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'BrowserPassword123!';

    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(adminEmail);
    await page.locator('#password').fill(adminPassword);
    await page.getByRole('button', { name: 'Войти в панель' }).click();
    await expect(page).toHaveURL(/\/admin$/);

    await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
    const aureliaCard = page.locator('.theme-card').filter({ hasText: 'Kaev Aurelia Account' });
    const activate = aureliaCard.getByRole('button', { name: 'Активировать' });
    if (await activate.count()) {
        await activate.click();
        await expect(page).toHaveURL(/\/admin\/account-themes$/);
    }

    await context.clearCookies();
    await signIn(page);
    await expect(page.locator('link[href*="account-themes/kaev-aurelia/assets/css/app.css"]')).toHaveCount(1);

    const promoLink = page.locator('.account-nav').getByRole('link', { name: 'Промокоды' });
    await promoLink.click();
    await expect(page).toHaveURL(/\/modules\/promo-codes$/);
    await expect(promoLink).toHaveClass(/active/);

    const activationSurface = page.locator('.promo-activation-surface');
    const formAside = page.locator('.account-form-aside');
    await expect(activationSurface).toBeVisible();
    await expect(formAside).toBeVisible();

    const activationRadius = await activationSurface.evaluate((element) => Number.parseFloat(getComputedStyle(element).borderTopLeftRadius));
    const asideStyle = await formAside.evaluate((element) => {
        const style = getComputedStyle(element);
        return {
            radius: Number.parseFloat(style.borderTopLeftRadius),
            position: style.position,
            overflow: style.overflow,
        };
    });

    expect(activationRadius).toBeGreaterThan(0);
    expect(asideStyle.radius).toBeGreaterThan(0);
    expect(asideStyle.position).toBe('relative');
    expect(asideStyle.overflow).toBe('hidden');

    await page.locator('.account-nav').getByRole('link', { name: 'Веб-инвентарь' }).click();
    await expect(page).toHaveURL(/\/account\/web-inventory$/);

    const inventorySurface = page.locator('.reward-inventory-shell');
    const inventoryRadius = await inventorySurface.evaluate((element) => Number.parseFloat(getComputedStyle(element).borderTopLeftRadius));
    const tabRadius = await page.locator('.reward-view-tabs a').first().evaluate((element) => Number.parseFloat(getComputedStyle(element).borderTopLeftRadius));

    expect(inventoryRadius).toBeGreaterThan(0);
    expect(tabRadius).toBeGreaterThan(0);
});
