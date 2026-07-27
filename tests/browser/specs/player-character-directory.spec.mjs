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
    await expect(page.locator('script[src*="/assets/account/js/navigation.js"]')).toHaveCount(1);
    await expect(page.locator('script[src*="account-themes/luxury/assets/js/navigation.js"]')).toHaveCount(0);
    await expect(page.locator('.account-overview-header')).toBeVisible();

    const accountMenu = page.locator('.account-profile-menu-topbar');
    await accountMenu.locator('summary').click();
    await expect(accountMenu.locator('.account-profile-balance')).toContainText(/Монеты|Coins/);

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

    await page.locator('.account-profile-menu-topbar > summary').click();
    await page.getByRole('link', { name: 'Настройки аккаунта', exact: true }).click();
    await expect(page).toHaveURL(/\/account\/profile$/);
    await page.getByRole('button', { name: 'Изменить аватар', exact: true }).first().click();
    const dialog = page.locator('[data-avatar-modal]');
    await expect(dialog).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Выбор аватара', exact: true })).toBeVisible();

    await page.locator('label:has(input[name="avatar_filename"][value="browser-avatar.png"])').click();
    await page.getByRole('button', { name: 'Сохранить аватар', exact: true }).click();

    await expect(page).toHaveURL(/\/account\/profile$/);
    await expect(page.getByText('Аватар сохранён.', { exact: true })).toBeVisible();
    await expect(page.locator('.account-profile-preview img[src*="/uploads/account-avatars/browser-avatar.png"]')).toBeVisible();
});

test('player can change the KaevCMS password from the separate security page', async ({ page }) => {
    const temporaryPassword = 'BrowserTemporary456!';

    await signIn(page);
    await page.locator('.account-profile-menu-topbar > summary').click();
    await page.getByRole('link', { name: 'Безопасность и пароль', exact: true }).click();
    await expect(page).toHaveURL(/\/account\/security$/);

    await page.locator('#current_password').fill(password);
    await page.locator('#password').fill(temporaryPassword);
    await page.locator('#password_confirmation').fill(temporaryPassword);
    await page.getByRole('button', { name: 'Сменить пароль', exact: true }).click();
    await expect(page.getByText('Пароль аккаунта изменён.', { exact: true })).toBeVisible();

    await page.locator('#current_password').fill(temporaryPassword);
    await page.locator('#password').fill(password);
    await page.locator('#password_confirmation').fill(password);
    await page.getByRole('button', { name: 'Сменить пароль', exact: true }).click();
    await expect(page.getByText('Пароль аккаунта изменён.', { exact: true })).toBeVisible();
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
    const promoResult = page.locator('[data-account-operation-modal]');
    await expect(promoResult).toBeVisible();
    await expect(promoResult.getByRole('heading', { name: 'Промокод активирован', exact: true })).toBeVisible();
    await expect(promoResult.getByText('Награды добавлены в веб-инвентарь сервера Browser World.')).toBeVisible();
    await expect(promoResult.locator('.account-operation-reward').filter({ hasText: 'Адена' })).toContainText('× 1 000 000');
    await expect(page.getByText('BROWSER2026', { exact: true })).toBeVisible();

    await promoResult.getByRole('link', { name: 'Открыть веб-инвентарь', exact: true }).click();
    await expect(page).toHaveURL(/\/account\/web-inventory\?server=\d+$/);
    await expect(page.getByText('Адена', { exact: true })).toBeVisible();
    await expect(page.getByText('1 000 000')).toBeVisible();
});

test('player claims the current daily reward into web inventory', async ({ page }) => {
    await signIn(page);

    await page.locator('.account-nav').getByRole('link', { name: 'Ежедневные награды' }).click();
    await expect(page).toHaveURL(/\/modules\/daily-rewards$/);
    await expect(page.getByText('Доступно сегодня', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Получить награду', exact: true }).click();

    await expect(page).toHaveURL(/\/modules\/daily-rewards/);
    const dailyResult = page.locator('[data-account-operation-modal]');
    await expect(dailyResult).toBeVisible();
    await expect(dailyResult.getByRole('heading', { name: 'Награда получена', exact: true })).toBeVisible();
    await expect(dailyResult.getByText('Награда ждёт вас в веб-инвентаре.', { exact: true })).toBeVisible();
    await expect(dailyResult.locator('.account-operation-reward').filter({ hasText: 'Адена' })).toContainText('× 250 000');
    await expect(page.getByText('Получено', { exact: true })).toBeVisible();

    await dailyResult.getByRole('link', { name: 'Открыть веб-инвентарь', exact: true }).click();
    await expect(page).toHaveURL(/\/account\/web-inventory\?server=\d+$/);
    const dailyRewardRow = page.locator('.reward-item-row').filter({ hasText: '250 000' });
    await expect(dailyRewardRow).toHaveCount(1);
    await expect(dailyRewardRow.getByText('Адена', { exact: true })).toBeVisible();
    await expect(dailyRewardRow.getByText('× 250 000', { exact: true })).toBeVisible();
});

test('aurelia player theme keeps shared runtime and active module navigation after SPA changes', async ({ page, context }) => {
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
    await expect(page.locator('script[src*="/assets/account/js/navigation.js"]')).toHaveCount(1);
    await expect(page.locator('script[src*="account-themes/kaev-aurelia/assets/js/navigation.js"]')).toHaveCount(0);

    await gotoWithLocalNetworkRetry(page, '/modules/daily-rewards?calendar=1&account=2');
    await expect(page).toHaveURL(/\/modules\/daily-rewards\?calendar=1&account=2$/);
    await page.locator('.account-language-switcher').getByRole('link', { name: 'EN', exact: true }).click();
    await expect(page).toHaveURL(/\/en\/account$/);
    await page.locator('.account-language-switcher').getByRole('link', { name: 'RU', exact: true }).click();
    await expect(page).toHaveURL(/\/ru\/account$/);

    const promoLink = page.locator('.account-nav').getByRole('link', { name: 'Промокоды' });
    await promoLink.click();
    await expect(page).toHaveURL(/\/modules\/promo-codes$/);
    await expect(promoLink).toHaveClass(/active/);
    await expect(page.locator('.promo-activation-surface')).toBeVisible();
    await expect(page.locator('.account-form-aside')).toBeVisible();
    await expect(page.getByTestId('promo-code-input')).toBeEditable();

    const inventoryLink = page.locator('.account-nav').getByRole('link', { name: 'Веб-инвентарь' });
    await inventoryLink.click();
    await expect(page).toHaveURL(/\/account\/web-inventory$/);
    await expect(inventoryLink).toHaveClass(/active/);
    await expect(page.locator('.reward-inventory-shell')).toBeVisible();
    await expect(page.locator('.reward-view-tabs').getByRole('link').first()).toBeVisible();
});
