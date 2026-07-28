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

const expectNoDocumentHorizontalOverflow = async (page, viewportLabel) => {
    const result = await page.evaluate(() => {
        const root = document.documentElement;
        const overflow = root.scrollWidth > root.clientWidth + 1;
        const offenders = overflow
            ? Array.from(document.body.querySelectorAll('*'))
                .map((element) => {
                    const rectangle = element.getBoundingClientRect();
                    return {
                        selector: element.id
                            ? `#${element.id}`
                            : `${element.tagName.toLowerCase()}${element.classList.length > 0 ? `.${Array.from(element.classList).join('.')}` : ''}`,
                        left: Math.round(rectangle.left),
                        right: Math.round(rectangle.right),
                        width: Math.round(rectangle.width),
                    };
                })
                .filter((element) => element.right > root.clientWidth + 1 || element.left < -1)
                .slice(0, 12)
            : [];

        return {
            clientWidth: root.clientWidth,
            scrollWidth: root.scrollWidth,
            offenders,
        };
    });

    expect(
        result.scrollWidth,
        `${viewportLabel}: document width ${result.scrollWidth}px exceeds ${result.clientWidth}px. Offenders: ${JSON.stringify(result.offenders)}`,
    ).toBeLessThanOrEqual(result.clientWidth + 1);
};

test.beforeEach(async ({ page }) => {
    await signIn(page);
});

test('administration loads the split stylesheet stack once and in order', async ({ page }) => {
    const expected = [
        'base.css',
        'layout.css',
        'content.css',
        'infrastructure.css',
        'components.css',
        'extensions.css',
        'catalogs.css',
    ];
    const stylesheetNames = async () => page.locator('link[data-navigate-track][href*="/assets/admin/css/"]').evaluateAll(
        (links) => links.map((link) => new URL(link.href).pathname.split('/').pop()),
    );

    expect(await stylesheetNames()).toEqual(expected);
    await expect(page.locator('link[href*="/assets/admin/css/app.css"]')).toHaveCount(0);

    await gotoWithLocalNetworkRetry(page, '/admin/news');
    expect(await stylesheetNames()).toEqual(expected);
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

    const settingsTabs = page.getByTestId('settings-section-tabs');
    await expect(settingsTabs).toBeVisible();
    await expect(settingsTabs.getByRole('link', { name: 'Сайт' })).toHaveAttribute('aria-current', 'page');
    await settingsTabs.getByRole('link', { name: 'Панель администратора' }).click();

    await expect(page).toHaveURL(/\/admin\/settings\/admin-panel$/);
    await expect(settingsTabs.getByRole('link', { name: 'Панель администратора' })).toHaveAttribute('aria-current', 'page');
    const adminPathSettings = page.getByTestId('admin-path-settings');
    const monitorSettings = page.getByTestId('server-monitor-settings');
    await expect(adminPathSettings.getByLabel('Суффикс адреса панели')).toBeVisible();
    await expect(adminPathSettings.getByRole('button', { name: 'Изменить адрес' })).toBeVisible();
    await expect(monitorSettings.getByLabel('Интервал обновления статуса')).toBeVisible();
    await expect(monitorSettings.getByRole('button', { name: 'Сохранить настройки мониторинга' })).toBeVisible();

    await settingsTabs.getByRole('link', { name: 'Системная информация' }).click();
    await expect(page).toHaveURL(/\/admin\/settings\/system$/);
    await expect(settingsTabs.getByRole('link', { name: 'Системная информация' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByText('Состояние компонентов').first()).toBeVisible();
    await expect(page.getByTestId('admin-path-settings')).toHaveCount(0);

    await settingsTabs.getByRole('link', { name: 'Игровые аккаунты' }).click();
    await expect(page).toHaveURL(/\/admin\/settings\/game-accounts$/);
    await expect(settingsLink).toHaveAttribute('data-current', '');
    await expect(settingsTabs.getByRole('link', { name: 'Игровые аккаунты' })).toHaveAttribute('aria-current', 'page');
    const gameAccountSettings = page.getByTestId('game-account-settings');
    await expect(gameAccountSettings.getByRole('spinbutton', { name: 'Максимум аккаунтов на пользователя CMS' })).toBeVisible();
    await expect(gameAccountSettings.locator('[data-game-account-limit-help]')).toContainText('Лимит считается суммарно');

    const mailLink = page.getByRole('link', { name: 'Почта', exact: true });
    await mailLink.click();
    await expect(page).toHaveURL(/\/admin\/settings\/mail$/);
    await expect(settingsLink).not.toHaveAttribute('data-current', '');
    await expect(mailLink).toHaveClass(/active/);
    await expect(page.locator('.mail-template-tabs')).toBeVisible();
    await expect(page.locator('.mail-template-tabs .admin-tab.active')).toBeVisible();
});

test('module foundation is available from the administrator sidebar', async ({ page }) => {
    await openMenuGroup(page, 'modules');
    await page.locator('[data-admin-menu-group="modules"]').getByRole('link', { name: 'Модули', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/modules$/);
    await expect(page.getByRole('heading', { name: 'Модули' }).first()).toBeVisible();
    await expect(page.getByText('Жизненный цикл модулей')).toBeVisible();
    await expect(page.getByText(/При отключении модуля его данные сохраняются/)).toBeVisible();

    const moduleCatalog = page.getByTestId('module-catalog');
    await expect(moduleCatalog).toHaveAttribute('data-layout', 'single-column');
    await expect(moduleCatalog.getByTestId('module-card')).toHaveCount(2);

    const promoModule = page.locator('[data-module-id="promo-codes"]');
    const dailyModule = page.locator('[data-module-id="daily-rewards"]');
    await expect(promoModule.getByRole('heading', { name: 'Promo Codes' })).toBeVisible();
    await expect(promoModule.locator('img[src*="/modules/promo-codes/image"]')).toBeVisible();
    await expect(promoModule.locator('.admin-catalog-side')).toBeVisible();
    await expect(dailyModule.getByRole('heading', { name: 'Daily Rewards' })).toBeVisible();
    await expect(dailyModule.locator('img[src*="/modules/daily-rewards/image"]')).toBeVisible();
    await expect(dailyModule.locator('.admin-catalog-side')).toBeVisible();
});

test('theme catalogues expose semantic single-column catalogues', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/themes');
    const publicCatalog = page.getByTestId('public-theme-catalog');
    await expect(publicCatalog).toHaveAttribute('data-layout', 'single-column');
    const publicThemes = publicCatalog.getByTestId('public-theme-card');
    await expect(publicThemes).toHaveCount(2);
    await expect(publicThemes.first().locator('.admin-catalog-heading')).toBeVisible();
    await expect(publicThemes.first().locator('.theme-catalog-preview')).toBeVisible();
    await expect(publicThemes.first().locator('.admin-catalog-side')).toBeVisible();

    await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
    const accountCatalog = page.getByTestId('account-theme-catalog');
    await expect(accountCatalog).toHaveAttribute('data-layout', 'single-column');
    const accountThemes = accountCatalog.getByTestId('account-theme-card');
    await expect(accountThemes).toHaveCount(2);
    await expect(accountThemes.first().locator('.admin-catalog-heading')).toBeVisible();
    await expect(accountThemes.first().locator('.theme-catalog-preview')).toBeVisible();
    await expect(accountThemes.first().locator('.admin-catalog-side')).toBeVisible();
});

test('login server settings keep network fields on a separate tab and actions available after connection test', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/settings/login-server');
    await page.getByRole('button', { name: 'Настроить' }).first().click();

    const dialog = page.getByTestId('login-server-dialog');
    const footer = page.getByTestId('login-server-dialog-footer');
    const saveButton = footer.getByRole('button', { name: 'Сохранить изменения' });

    await expect(dialog).toBeVisible();
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
    await expect(footer).toBeVisible();
    await expect(saveButton).toBeVisible();
    await expect(saveButton).toBeEnabled();
});

test('game server settings keep fields separated by accessible tabs', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/settings/game-server');
    await page.getByRole('button', { name: 'Настроить' }).first().click();

    const dialog = page.getByTestId('game-server-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('tab', { name: 'Основное' })).toHaveAttribute('aria-selected', 'true');

    await dialog.getByRole('tab', { name: 'Статистика' }).click();
    await expect(dialog.getByRole('tab', { name: 'Статистика' })).toHaveAttribute('aria-selected', 'true');
    await expect(dialog.getByText('Публичная статистика').first()).toBeVisible();

    await dialog.getByRole('tab', { name: 'Разное' }).click();
    await expect(dialog.getByRole('tab', { name: 'Разное' })).toHaveAttribute('aria-selected', 'true');
    await expect(dialog.getByText('Режим обслуживания')).toBeVisible();
    await expect(dialog.getByText('Дополнительные сетевые настройки')).toBeVisible();
    await expect(page.getByTestId('game-server-dialog-footer')).toBeVisible();
});

test('administration surfaces remain available across catalogue navigation', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/news');
    await expect(page.locator('.admin-overview').first()).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Новости', exact: true }).first()).toBeVisible();

    await openMenuGroup(page, 'users');
    await page.getByRole('link', { name: 'Пользователи', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/users$/);
    await expect(page.locator('.admin-filter-bar')).toBeVisible();

    await page.getByRole('link', { name: 'Журнал действий', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/logs/);
    await expect(page.locator('.admin-subtabs')).toBeVisible();

    const table = page.locator('.admin-table-wrap');
    if (await table.count()) {
        await expect(table.first()).toBeVisible();
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

test('system information shows safe external database diagnostics on mobile', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/settings/system');

    const diagnostics = page.getByTestId('external-database-diagnostics');
    await expect(diagnostics).toBeVisible();
    await expect(diagnostics.getByRole('heading', { name: 'Диагностика внешних баз', exact: true })).toBeVisible();
    await expect(diagnostics.getByText('Browser LoginServer', { exact: true })).toBeVisible();
    await expect(diagnostics.getByText('Browser World', { exact: true })).toBeVisible();
    await expect(diagnostics.getByText('17 мс', { exact: true })).toBeVisible();
    await expect(diagnostics.getByText('Mobius legacy', { exact: true })).toBeVisible();
    await expect(diagnostics.getByText('Создание аккаунтов', { exact: true })).toBeVisible();
    await expect(diagnostics.getByText('characters', { exact: true })).toBeVisible();
    await expect(diagnostics.getByRole('button', { name: 'Проверить внешние базы', exact: true })).toBeVisible();
    await expect(page.getByText('browser_test', { exact: true })).toHaveCount(0);
    await expect(page.getByText('browser_test_unsupported', { exact: true })).toHaveCount(0);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: 'EN', exact: true }).click();
    await expect(page.getByTestId('external-database-diagnostics')).toBeVisible();
    await expect(page.getByText('optional_character_services_with_extended_identifier', { exact: true })).toBeVisible();

    const viewportMatrix = [
        { width: 390, height: 844 },
        { width: 768, height: 900 },
        { width: 800, height: 900 },
        { width: 1024, height: 900 },
        { width: 1440, height: 1000 },
    ];

    for (const viewport of viewportMatrix) {
        await test.step(`system diagnostics fit ${viewport.width}px viewport`, async () => {
            await page.setViewportSize(viewport);
            await expect(page.getByTestId('external-database-diagnostics')).toBeVisible();
            await expect(page.getByText('optional_character_services_with_extended_identifier', { exact: true })).toBeVisible();
            await expectNoDocumentHorizontalOverflow(page, `${viewport.width}px system diagnostics`);
        });
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('.admin-account-copy')).toBeHidden();
    await expect(page.locator('.admin-account-chevron')).toBeHidden();

    await page.getByRole('button', { name: 'RU', exact: true }).click();
    await expect(page.getByTestId('external-database-diagnostics')).toBeVisible();
});

test('removed legacy dashboard endpoint returns not found', async ({ page }) => {
    const response = await gotoWithLocalNetworkRetry(page, '/admin/dashboard');

    expect(response).not.toBeNull();
    expect(response.status()).toBe(404);
});

test('reward queue journal explains review operations and remains usable on mobile', async ({ page }) => {
    await page.getByRole('link', { name: 'Очередь наград', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/reward-deliveries$/);
    await expect(page.getByRole('heading', { name: 'Очередь наград', exact: true }).first()).toBeVisible();
    const row = page.locator('[data-testid="reward-delivery-row"][data-status="review"]').first();
    await expect(row).toContainText('Требует проверки');
    await expect(row).toContainText('Ответ базы был потерян');
    await expect(row.getByRole('button', { name: 'Проверить ещё раз', exact: true })).toBeVisible();
    await expect(row.locator('.reward-operation-uuid')).toHaveText(/[0-9a-f-]{36}/i);

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(row).toBeVisible();
    await expect(row.getByText('Адена', { exact: true })).toBeVisible();
    await expect(row.getByRole('button', { name: 'Проверить ещё раз', exact: true })).toBeVisible();
    const hasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(hasHorizontalOverflow).toBe(false);
});

test('daily rewards module edits calendar days through dialog actions', async ({ page }) => {
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
    await expect(dialog.locator('[data-daily-item-id]').first()).toBeEditable();
    await expect(dialog.locator('[data-daily-item-amount]').first()).toBeEditable();

    await dialog.getByTestId('daily-reward-dialog-card').dispatchEvent('pointerdown', {
        button: 0,
        isPrimary: true,
    });
    await expect(dialog).toBeVisible();
    await dialog.dispatchEvent('pointerdown', { button: 0, isPrimary: true });
    await expect(dialog).not.toBeVisible();

    await configuredDay.click();
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Применить', exact: true }).click();
    await expect(dialog).not.toBeVisible();
});

test('promo code module manages dynamic rewards and status controls', async ({ page }) => {
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

    const rewardsEditor = page.getByTestId('promo-rewards-editor');
    await expect(rewardsEditor.locator('[data-promo-reward-row]')).toHaveCount(1);
    const addRewardButton = rewardsEditor.locator('[data-promo-reward-add]');
    await expect(addRewardButton).toContainText('Добавить предмет');
    await addRewardButton.click();
    await expect(rewardsEditor.locator('[data-promo-reward-row]')).toHaveCount(2);
    await rewardsEditor.locator('[data-promo-reward-row]').nth(1).getByRole('button', { name: 'Удалить предмет из промокода' }).click();
    await expect(rewardsEditor.locator('[data-promo-reward-row]')).toHaveCount(1);

    await page.locator('#code').fill('browser-new');
    await page.locator('#game_server_id').selectOption({ label: 'Browser World' });
    await page.locator('#reward_item_0').fill('57');
    await expect(rewardsEditor.locator('[data-promo-reward-name]').first()).toContainText('Адена');
    await expect(rewardsEditor.locator('[data-promo-reward-preview]').first()).toBeVisible();
    await page.locator('#reward_amount_0').fill('500');
    await expect(page.locator('#reward_item_0')).toHaveValue('57');
    await expect(page.locator('#reward_amount_0')).toHaveValue('500');
    await page.getByRole('button', { name: 'Создать промокод', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/extensions\/promo-codes$/);
    const createdCode = page.locator('.content-row').filter({ hasText: 'BROWSER-NEW' });
    await expect(createdCode).toBeVisible();
    await expect(createdCode).toContainText('Общий лимит: без лимита');

    page.once('dialog', (dialog) => dialog.accept());
    await createdCode.getByRole('button', { name: 'Удалить', exact: true }).click();
    await expect(page.getByText('BROWSER-NEW', { exact: true })).toHaveCount(0);
});
