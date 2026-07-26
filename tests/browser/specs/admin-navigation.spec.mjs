import { expect, test } from '@playwright/test';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const email = process.env.PLAYWRIGHT_ADMIN_EMAIL || 'browser-admin@example.test';
const password = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'BrowserPassword123!';

const openMenuGroup = async (page, group) => {
    const details = page.locator(`[data-admin-menu-group="${group}"]`);
    if (!(await details.evaluate((element) => element.open))) {
        await details.locator('summary').click();
    }
};

const signIn = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Войти в панель' }).click();
    await expect(page).toHaveURL(/\/admin$/);
};

test.beforeEach(async ({ page }) => {
    await signIn(page);
});

test('news editor initializes again after SPA navigation', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/news/create');

    const canvas = page.locator('#body-editor-ru');
    const source = page.locator('#body_ru');
    await expect(canvas).toBeVisible();

    await canvas.evaluate((element) => {
        element.innerHTML = '<p>Первый текст</p>';
        element.dispatchEvent(new InputEvent('input', { bubbles: true }));
    });
    await expect(source).toHaveValue('<p>Первый текст</p>');

    await page.getByRole('link', { name: 'Настройки', exact: true }).click();
    await page.locator('.settings-section-tabs').getByRole('link', { name: 'Системная информация' }).click();
    await expect(page).toHaveURL(/\/admin\/settings\/system$/);
    await openMenuGroup(page, 'content');
    await page.getByRole('link', { name: 'Новости' }).click();
    await page.getByRole('link', { name: /Создать/ }).first().click();

    await expect(canvas).toBeVisible();
    await canvas.evaluate((element) => {
        element.innerHTML = '<p>Повторная инициализация</p>';
        element.dispatchEvent(new InputEvent('input', { bubbles: true }));
    });
    await expect(source).toHaveValue('<p>Повторная инициализация</p>');
});

test('two-factor QR is rendered after leaving and returning', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/account/security');
    await page.locator('#current_password').fill(password);
    await page.getByRole('button', { name: 'Включить 2FA' }).click();

    const qr = page.locator('[data-two-factor-qr] svg');
    await expect(qr).toHaveCount(1);

    await openMenuGroup(page, 'content');
    await page.getByRole('link', { name: 'Новости' }).click();
    await expect(page).toHaveURL(/\/admin\/news$/);
    await page.goBack();

    await expect(page).toHaveURL(/\/admin\/account\/security$/);
    await expect(page.locator('[data-two-factor-qr] svg')).toHaveCount(1);
});

test('persisted sidebar keeps group state during navigation and history changes', async ({ page }) => {
    const appearanceGroup = page.locator('[data-admin-menu-group="appearance"]');
    await expect(appearanceGroup.locator('summary')).toContainText('Темы');
    await appearanceGroup.locator('summary').click();
    await expect(appearanceGroup).toHaveAttribute('open', '');
    await expect(appearanceGroup.getByRole('link', { name: 'Сайт', exact: true })).toBeVisible();
    await expect(appearanceGroup.getByRole('link', { name: 'Кабинет', exact: true })).toBeVisible();

    await openMenuGroup(page, 'servers');
    await page.getByRole('link', { name: 'Игровые серверы' }).click();
    await expect(page).toHaveURL(/\/admin\/settings\/game-server$/);
    await expect(appearanceGroup).toHaveAttribute('open', '');

    await page.goBack();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(appearanceGroup).toHaveAttribute('open', '');

    await page.goForward();
    await expect(page).toHaveURL(/\/admin\/settings\/game-server$/);
    await expect(appearanceGroup).toHaveAttribute('open', '');
});

test('settings use one sidebar entry and local tabs', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/settings');

    const settingsLink = page.locator('[data-admin-settings-link]');
    await expect(settingsLink).toHaveCount(1);
    await expect(settingsLink).toHaveAttribute('data-current', '');

    const settingsTabs = page.locator('.settings-section-tabs');
    await expect(settingsTabs).toBeVisible();
    await expect(settingsTabs).toHaveCSS('background-color', 'rgb(237, 242, 248)');
    await expect(settingsTabs.locator('.admin-tab.active')).toHaveCSS('background-color', 'rgb(37, 99, 235)');
    await settingsTabs.getByRole('link', { name: 'Панель администратора' }).click();

    await expect(page).toHaveURL(/\/admin\/settings\/admin-panel$/);
    await expect(page.getByText('Адрес панели управления').first()).toBeVisible();
    await expect(page.getByText('Мониторинг серверов').first()).toBeVisible();

    const adminPathInputBox = await page.locator('#admin_path_suffix').boundingBox();
    const changeAddressButtonBox = await page.getByRole('button', { name: 'Изменить адрес' }).boundingBox();
    const monitorSelectBox = await page.locator('#refresh_interval_seconds').boundingBox();
    const monitorButtonBox = await page.getByRole('button', { name: 'Сохранить настройки мониторинга' }).boundingBox();

    expect(adminPathInputBox).not.toBeNull();
    expect(changeAddressButtonBox).not.toBeNull();
    expect(monitorSelectBox).not.toBeNull();
    expect(monitorButtonBox).not.toBeNull();
    expect(changeAddressButtonBox.y).toBeGreaterThanOrEqual(adminPathInputBox.y + adminPathInputBox.height);
    expect(monitorButtonBox.y).toBeGreaterThanOrEqual(monitorSelectBox.y + monitorSelectBox.height);

    await settingsTabs.getByRole('link', { name: 'Системная информация' }).click();
    await expect(page).toHaveURL(/\/admin\/settings\/system$/);
    await expect(page.getByText('Состояние компонентов').first()).toBeVisible();
    await expect(page.getByText('Адрес панели управления')).toHaveCount(0);
    await expect(page.getByText('Мониторинг серверов')).toHaveCount(0);

    await settingsTabs.getByRole('link', { name: 'Игровые аккаунты' }).click();
    await expect(page).toHaveURL(/\/admin\/settings\/game-accounts$/);
    await expect(settingsLink).toHaveAttribute('data-current', '');
    await expect(page.getByText('Максимум аккаунтов на пользователя CMS')).toBeVisible();
    await expect(page.locator('label.settings-field small br')).toHaveCount(1);

    const maxAccountsInputBox = await page.locator('input[name="max_accounts"]').boundingBox();
    const limitHelpBox = await page.locator('[data-game-account-limit-help]').boundingBox();
    expect(maxAccountsInputBox).not.toBeNull();
    expect(limitHelpBox).not.toBeNull();
    expect(limitHelpBox.y).toBeGreaterThanOrEqual(maxAccountsInputBox.y + maxAccountsInputBox.height);

    const mailLink = page.getByRole('link', { name: 'Почта', exact: true });
    await mailLink.click();
    await expect(page).toHaveURL(/\/admin\/settings\/mail$/);
    await expect(settingsLink).not.toHaveAttribute('data-current', '');
    await expect(mailLink).toHaveClass(/active/);
    await expect(page.locator('.mail-template-tabs')).toHaveCSS('background-color', 'rgb(237, 242, 248)');
    await expect(page.locator('.mail-template-tabs .admin-tab.active')).toHaveCSS('background-color', 'rgb(37, 99, 235)');
});

test('module foundation is available from the administrator sidebar', async ({ page }) => {
    await openMenuGroup(page, 'modules');
    await page.locator('[data-admin-menu-group="modules"]').getByRole('link', { name: 'Модули', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/modules$/);
    await expect(page.getByRole('heading', { name: 'Модули' }).first()).toBeVisible();
    await expect(page.getByText('Жизненный цикл модулей')).toBeVisible();
    await expect(page.getByText(/При отключении модуля его данные сохраняются/)).toBeVisible();
    const promoModule = page.locator('[data-module-id="promo-codes"]');
    const dailyModule = page.locator('[data-module-id="daily-rewards"]');
    await expect(promoModule).toHaveCount(1);
    await expect(dailyModule).toHaveCount(1);
    await expect(promoModule).toBeVisible();
    await expect(promoModule.locator('img[src*="/modules/promo-codes/image"]')).toBeVisible();
    await expect(dailyModule.locator('img[src*="/modules/daily-rewards/image"]')).toBeVisible();

    const moduleList = page.locator('[data-module-list]');
    const promoBox = await promoModule.boundingBox();
    const dailyBox = await dailyModule.boundingBox();
    const listBox = await moduleList.boundingBox();
    const headingBox = await promoModule.locator('.admin-catalog-heading').boundingBox();
    const previewBox = await promoModule.locator('.module-catalog-preview').boundingBox();
    const gridTemplateColumns = await moduleList.evaluate((element) => getComputedStyle(element).gridTemplateColumns);
    expect(promoBox).not.toBeNull();
    expect(dailyBox).not.toBeNull();
    expect(listBox).not.toBeNull();
    expect(headingBox).not.toBeNull();
    expect(previewBox).not.toBeNull();
    expect(gridTemplateColumns.trim().split(/\s+/)).toHaveLength(1);
    expect(Math.abs(promoBox.width - listBox.width)).toBeLessThanOrEqual(1);
    expect(Math.abs(dailyBox.width - listBox.width)).toBeLessThanOrEqual(1);
    expect(headingBox.x).toBeLessThan(previewBox.x);
    expect(Math.round(previewBox.width)).toBe(124);
    expect(Math.round(previewBox.height)).toBe(124);
});

test('theme catalogues use stable single-row cards with copy on the left', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/themes');
    const publicThemes = page.locator('[data-theme-list] .theme-card');
    await expect(publicThemes).toHaveCount(2);

    const firstPublicTheme = publicThemes.first();
    const secondPublicTheme = publicThemes.nth(1);
    const firstPublicBox = await firstPublicTheme.boundingBox();
    const secondPublicBox = await secondPublicTheme.boundingBox();
    const publicHeadingBox = await firstPublicTheme.locator('.admin-catalog-heading').boundingBox();
    const publicPreviewBox = await firstPublicTheme.locator('.theme-catalog-preview').boundingBox();
    expect(firstPublicBox).not.toBeNull();
    expect(secondPublicBox).not.toBeNull();
    expect(publicHeadingBox).not.toBeNull();
    expect(publicPreviewBox).not.toBeNull();
    expect(Math.abs(firstPublicBox.x - secondPublicBox.x)).toBeLessThanOrEqual(1);
    expect(secondPublicBox.y).toBeGreaterThan(firstPublicBox.y + firstPublicBox.height - 1);
    expect(publicHeadingBox.x).toBeLessThan(publicPreviewBox.x);
    expect(Math.round(publicPreviewBox.width)).toBe(230);
    expect(Math.round(publicPreviewBox.height)).toBe(130);

    await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
    const accountThemes = page.locator('[data-account-theme-list] .theme-card');
    await expect(accountThemes).toHaveCount(2);
    const firstAccountBox = await accountThemes.first().boundingBox();
    const secondAccountBox = await accountThemes.nth(1).boundingBox();
    expect(firstAccountBox).not.toBeNull();
    expect(secondAccountBox).not.toBeNull();
    expect(Math.abs(firstAccountBox.x - secondAccountBox.x)).toBeLessThanOrEqual(1);
    expect(secondAccountBox.y).toBeGreaterThan(firstAccountBox.y + firstAccountBox.height - 1);
});

test('login server settings keep network fields on a separate tab and footer fixed after connection test', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/settings/login-server');
    await page.getByRole('button', { name: 'Настроить' }).first().click();

    const dialog = page.getByRole('dialog', { name: /Browser LoginServer|Настройки подключения/ });
    const footer = dialog.locator('.server-drawer-footer');
    const saveButton = footer.getByRole('button', { name: 'Сохранить изменения' });

    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.server-drawer-tabs')).toHaveCSS('background-color', 'rgb(237, 242, 248)');
    await expect(dialog.getByRole('tab', { name: 'Основное' })).toHaveAttribute('aria-selected', 'true');
    await expect(dialog.getByText('Подключение к базе данных')).toBeVisible();
    await expect(dialog.getByText('Дополнительные сетевые настройки')).toHaveCount(0);

    await dialog.getByRole('tab', { name: 'Сетевые настройки' }).click();
    await expect(dialog.getByRole('tab', { name: 'Сетевые настройки' })).toHaveAttribute('aria-selected', 'true');
    await expect(dialog.getByRole('heading', { name: 'Дополнительные сетевые настройки' })).toBeVisible();
    await expect(dialog.getByLabel('Адрес службы')).toBeVisible();
    await expect(dialog.getByLabel('Порт службы')).toBeVisible();

    await dialog.getByRole('tab', { name: 'Основное' }).click();
    await dialog.locator('#live_login_driver').selectOption('rusacis');
    await dialog.locator('#live_login_port').fill('1');
    await footer.getByRole('button', { name: 'Проверить подключение' }).click();
    await expect(dialog.locator('.database-test-report')).toBeVisible({ timeout: 10_000 });
    await expect(saveButton).toBeVisible();

    const dialogBox = await dialog.boundingBox();
    const saveButtonBox = await saveButton.boundingBox();
    expect(dialogBox).not.toBeNull();
    expect(saveButtonBox).not.toBeNull();
    expect(saveButtonBox.y + saveButtonBox.height).toBeLessThanOrEqual(dialogBox.y + dialogBox.height);
});

test('game server settings keep fields separated by tabs', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/settings/game-server');
    await page.getByRole('button', { name: 'Настроить' }).first().click();

    const dialog = page.getByRole('dialog', { name: /L2Server|Игровой сервер|Настройки игрового сервера/ });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.server-drawer-tabs')).toHaveCSS('background-color', 'rgb(237, 242, 248)');
    await expect(dialog.getByRole('tab', { name: 'Основное' })).toHaveAttribute('aria-selected', 'true');
    await expect(dialog.getByRole('tab', { name: 'Основное' })).toHaveCSS('background-color', 'rgb(255, 255, 255)');

    await dialog.getByRole('tab', { name: 'Статистика' }).click();
    await expect(dialog.getByRole('tab', { name: 'Статистика' })).toHaveAttribute('aria-selected', 'true');
    await expect(dialog.getByText('Публичная статистика').first()).toBeVisible();

    await dialog.getByRole('tab', { name: 'Разное' }).click();
    await expect(dialog.getByRole('tab', { name: 'Разное' })).toHaveAttribute('aria-selected', 'true');
    await expect(dialog.getByText('Режим обслуживания')).toBeVisible();
    await expect(dialog.getByText('Дополнительные сетевые настройки')).toBeVisible();
});

test('admin catalogues share enterprise surfaces', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/news');
    const newsOverview = page.locator('.admin-overview').first();
    await expect(newsOverview).toBeVisible();
    await expect(newsOverview).toHaveCSS('border-radius', '12px');
    await expect(newsOverview).not.toHaveCSS('box-shadow', 'none');

    await openMenuGroup(page, 'users');
    await page.getByRole('link', { name: 'Пользователи', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/users$/);
    await expect(page.locator('.admin-filter-bar')).toHaveCSS('border-radius', '12px');

    await page.getByRole('link', { name: 'Журнал действий', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/logs/);
    await expect(page.locator('.admin-subtabs')).toHaveCSS('background-color', 'rgb(237, 242, 248)');

    const table = page.locator('.admin-table-wrap');
    if (await table.count()) {
        await expect(table.first()).toHaveCSS('border-radius', '12px');
    }
});

test('administrator role selector explains the selected access level', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/administrators');
    await expect(page.locator('.administrator-role-badge.role-owner').first()).toContainText('Владелец');

    await page.getByRole('link', { name: 'Создать администратора' }).click();
    const roleSelect = page.locator('[data-admin-role-select]');
    const roleDescription = page.locator('[data-admin-role-description]');

    await expect(roleSelect).toBeVisible();
    await expect(roleSelect.locator('option[value="moderator"]')).toHaveCount(0);

    await roleSelect.selectOption('administrator');
    await expect(roleDescription).toContainText('не может управлять владельцами');

    await roleSelect.selectOption('editor');
    await expect(roleDescription).toContainText('Работает только с новостями');
});

test('dashboard shows administrator runtime diagnostics', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin');

    const runtimeCard = page.locator('.dashboard-runtime-card');
    await expect(runtimeCard).toBeVisible();
    await expect(runtimeCard.getByText('Системные процессы')).toBeVisible();
    await expect(runtimeCard.getByText('Планировщик Laravel')).toBeVisible();
    await expect(runtimeCard.getByText('Обработка очереди')).toBeVisible();
    await expect(runtimeCard.getByText('Ожидающие задания')).toBeVisible();
    await expect(runtimeCard.getByText('Ошибки очереди')).toBeVisible();
    await expect(runtimeCard.getByText('Очередь почты')).toHaveCount(0);
});

test('queue management opens from dashboard diagnostics', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin');
    await page.locator('.dashboard-runtime-card').getByRole('link', { name: 'Подробнее об очередях' }).click();

    await expect(page).toHaveURL(/\/admin\/settings\/system\/queue$/);
    await expect(page.getByRole('heading', { name: 'Управление очередями' }).first()).toBeVisible();
    await expect(page.getByText('Текущее состояние очередей')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Хранение служебных данных', exact: true })).toBeVisible();
    await expect(page.getByText('Payload очереди и полные тексты исключений скрыты.')).toBeVisible();
});

test('system information reports APP_KEY encryption health without exposing secrets', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/settings/system');

    const encryptionCard = page.getByRole('heading', { name: 'Шифрование APP_KEY' }).locator('..');
    await expect(encryptionCard).toBeVisible();
    await expect(encryptionCard.getByText('Зашифрованные значения', { exact: true })).toBeVisible();
    await expect(encryptionCard.getByText('Недоступные значения', { exact: true })).toBeVisible();
    await expect(page.getByText('APP_KEY encryption')).toHaveCount(0);
});

test('removed legacy dashboard endpoint returns not found', async ({ page }) => {
    const response = await gotoWithLocalNetworkRetry(page, '/admin/dashboard');

    expect(response).not.toBeNull();
    expect(response.status()).toBe(404);
});

test('reward queue journal is available from the administrator sidebar', async ({ page }) => {
    await page.getByRole('link', { name: 'Очередь наград', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/reward-deliveries$/);
    await expect(page.getByRole('heading', { name: 'Очередь наград', exact: true }).first()).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Передач в очередь пока нет', exact: true })).toBeVisible();
});

test('daily rewards module edits calendar days in a visual dialog', async ({ page }) => {
    await openMenuGroup(page, 'modules');
    const modulesGroup = page.locator('[data-admin-menu-group="modules"]');
    await modulesGroup.getByRole('link', { name: 'Ежедневные награды', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/extensions\/daily-rewards$/);
    await page.locator('.content-row').first().getByRole('link', { name: 'Изменить', exact: true }).click();
    await expect(page.locator('.daily-reward-admin-calendar-grid')).toBeVisible();

    const configuredDay = page.locator('[data-daily-day-tile].is-enabled').first();
    await configuredDay.click();
    const dialog = page.locator('[data-daily-day][open]');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-daily-item-row]')).toHaveCount(2);
    await expect(dialog.getByText('Адена', { exact: true })).toBeVisible();
    await expect(dialog.locator('[data-daily-item-preview]').first()).toBeVisible();

    await dialog.getByRole('button', { name: /Добавить предмет/ }).click();
    await expect(dialog.locator('[data-daily-item-row]')).toHaveCount(3);
    await expect(page.locator('[data-daily-unsaved]:visible').first()).toContainText('Есть несохранённые изменения');
    const itemInput = dialog.locator('[data-daily-item-id]').first();
    const amountInput = dialog.locator('[data-daily-item-amount]').first();
    const itemBox = await itemInput.boundingBox();
    const amountBox = await amountInput.boundingBox();
    expect(itemBox).not.toBeNull();
    expect(amountBox).not.toBeNull();
    expect(Math.abs((itemBox?.y ?? 0) - (amountBox?.y ?? 0))).toBeLessThanOrEqual(1);
    const dialogCard = dialog.locator('.daily-reward-admin-dialog-card');
    const cardBox = await dialogCard.boundingBox();
    expect(cardBox).not.toBeNull();
    await page.mouse.move((cardBox?.x ?? 0) + 30, (cardBox?.y ?? 0) + 30);
    await page.mouse.down();
    await page.mouse.move(2, 2);
    await page.mouse.up();
    await expect(dialog).toBeVisible();
    await page.mouse.move(2, 2);
    await page.mouse.down();
    await expect(dialog).not.toBeVisible();
    await page.mouse.up();

    await configuredDay.click();
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Применить', exact: true }).click();
    await expect(dialog).not.toBeVisible();
});

test('promo code module provides compact dynamic rewards deletion and status controls', async ({ page }) => {
    const modulesGroup = page.locator('[data-admin-menu-group="modules"]');
    await openMenuGroup(page, 'modules');
    await expect(modulesGroup.getByRole('link', { name: 'Модули', exact: true })).toBeVisible();
    await modulesGroup.getByRole('link', { name: 'Промокоды', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/extensions\/promo-codes$/);
    await expect(page.getByRole('heading', { name: 'Промокоды', exact: true }).first()).toBeVisible();
    await expect(page.getByText('BROWSER2026', { exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'Создать промокод', exact: true }).click();
    await expect(page.locator('#starts_at')).toHaveAttribute('type', 'datetime-local');
    await expect(page.locator('#ends_at')).toHaveAttribute('type', 'datetime-local');
    await expect(page.locator('#game_server_id').getByRole('option', { name: 'Выберите сервер' })).toHaveCount(1);
    await expect(page.getByText('Укажите 0, чтобы общий лимит не применялся.')).toBeVisible();
    await expect(page.locator('[data-promo-reward-row]')).toHaveCount(1);

    const addRewardButton = page.locator('[data-promo-reward-add]');
    await expect(addRewardButton).toContainText('Добавить предмет');
    await addRewardButton.click();
    await expect(page.locator('[data-promo-reward-row]')).toHaveCount(2);
    await page.locator('[data-promo-reward-row]').nth(1).getByRole('button', { name: 'Удалить предмет из промокода' }).click();
    await expect(page.locator('[data-promo-reward-row]')).toHaveCount(1);

    await page.locator('#code').fill('browser-new');
    await page.locator('#game_server_id').selectOption({ label: 'Browser World' });
    await page.locator('#reward_item_0').fill('57');
    await expect(page.locator('[data-promo-reward-name]').first()).toContainText('Адена');
    await expect(page.locator('[data-promo-reward-preview]').first()).toBeVisible();
    await page.locator('#reward_amount_0').fill('500');
    const promoItemBox = await page.locator('#reward_item_0').boundingBox();
    const promoAmountBox = await page.locator('#reward_amount_0').boundingBox();
    expect(promoItemBox).not.toBeNull();
    expect(promoAmountBox).not.toBeNull();
    expect(Math.abs((promoItemBox?.y ?? 0) - (promoAmountBox?.y ?? 0))).toBeLessThanOrEqual(1);
    await page.getByRole('button', { name: 'Создать промокод', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/extensions\/promo-codes$/);
    const createdCode = page.locator('.content-row').filter({ hasText: 'BROWSER-NEW' });
    await expect(createdCode).toBeVisible();
    await expect(createdCode).toContainText('Общий лимит: без лимита');

    page.once('dialog', (dialog) => dialog.accept());
    await createdCode.getByRole('button', { name: 'Удалить', exact: true }).click();
    await expect(page.getByText('BROWSER-NEW', { exact: true })).toHaveCount(0);
});
