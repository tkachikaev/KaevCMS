import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const playerEmail = process.env.PLAYWRIGHT_PLAYER_EMAIL || 'browser-player@example.test';
const playerPassword = process.env.PLAYWRIGHT_PLAYER_PASSWORD || 'BrowserPlayerPassword123!';
const adminEmail = process.env.PLAYWRIGHT_ADMIN_EMAIL || 'browser-admin@example.test';
const adminPassword = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'BrowserPassword123!';

const signInPlayer = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/login');
    await page.locator('#login').fill(playerEmail);
    await page.locator('#password').fill(playerPassword);
    await submitLogin({
        page,
        postPath: '/login',
        label: 'Player login',
        submit: () => page.locator('form').getByRole('button').click(),
    });
    await expect(page).toHaveURL(/\/account$/);
};

const signInAdmin = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(adminEmail);
    await page.locator('#password').fill(adminPassword);
    await submitLogin({
        page,
        postPath: '/admin/login',
        label: 'Administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await expect(page).toHaveURL(/\/admin$/);
};

const setNoReloadMarker = async (page) => {
    await page.evaluate(() => {
        window.__supportNoReloadMarker = crypto.randomUUID();
    });

    return page.evaluate(() => window.__supportNoReloadMarker);
};

const expectNoReload = async (page, marker) => {
    await expect.poll(() => page.evaluate(() => window.__supportNoReloadMarker)).toBe(marker);
};

test('player and administrator complete the support ticket conversation flow without page reloads', async ({ page, context }) => {
    const subject = `Browser support ${Date.now()}`;

    await signInPlayer(page);
    await page.locator('.account-nav a[href$="/modules/support-tickets"]').click();
    await expect(page).toHaveURL(/\/modules\/support-tickets$/);
    await expect(page.getByRole('heading', { name: 'Мои обращения', exact: true })).toBeVisible();
    await expect(page.locator('[data-testid="support-ticket-create-form"]')).toHaveCount(0);
    await page.getByRole('button', { name: /Создать обращение/, exact: false }).click();
    await expect(page.locator('[data-testid="support-ticket-create-form"]')).toBeVisible();
    await page.locator('select[name="category"]').selectOption('technical_problem');
    await page.locator('input[name="subject"]').fill(subject);
    await page.locator('[data-testid="support-ticket-create-form"] textarea[name="body"]').fill('Проверка нового обращения через браузерный тест.');
    await expect(page.locator('[data-character-counter]').first()).toContainText(`${subject.length} / 120`);
    await page.locator('[data-testid="support-ticket-create-form"]').getByRole('button', { name: 'Создать обращение', exact: true }).click();

    await expect(page).toHaveURL(/\/modules\/support-tickets\/\d+$/);
    const ticketPath = new URL(page.url()).pathname;
    await expect(page.getByRole('heading', { name: subject, exact: true })).toBeVisible();
    await expect(page.getByText('Новое', { exact: true })).toBeVisible();
    await expect(page.locator('[data-support-conversation]')).toHaveCSS('overflow-y', 'auto');

    await context.clearCookies();
    await signInAdmin(page);
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/support-tickets');
    await expect(page.locator('[data-testid="support-ticket-filters"]')).toHaveCSS('display', 'grid');
    await expect(page.getByRole('heading', { name: 'Фильтры', exact: true })).toHaveCount(0);
    const ticketRow = page.locator('.support-admin-ticket-row').filter({ hasText: subject });
    await expect(ticketRow).toHaveCount(1);
    await ticketRow.getByRole('link', { name: 'Открыть обращение', exact: true }).click();
    await expect(page.getByRole('heading', { name: subject, exact: true })).toBeVisible();
    await expect(page.locator('[data-testid="support-admin-ticket-layout"]')).toHaveCSS('display', 'grid');
    await expect(page.locator('[data-testid="support-ticket-admin-heading"]')).toHaveCSS('display', 'grid');
    await expect(page.locator('.support-admin-ticket-side').getByRole('heading', { name: 'Данные обращения', exact: true })).toBeVisible();

    const noteToggle = page.locator('[data-testid="internal-note-toggle"]');
    await expect(page.locator('[data-testid="internal-note-form"]')).toHaveCount(0);
    await noteToggle.click();
    await expect(page.locator('[data-testid="internal-note-form"]')).toBeVisible();
    await expect(page.locator('[data-testid="internal-note-form"]')).not.toHaveCSS('border-style', 'dashed');
    await noteToggle.click();
    await expect(page.locator('[data-testid="internal-note-form"]')).toHaveCount(0);

    const staffReplyForm = page.locator('[data-testid="staff-reply-form"]');
    await expect(staffReplyForm.locator('textarea[name="body"]')).toHaveCSS('border-radius', '12px');
    const originalStaffReply = 'Уточните, пожалуйста, когда появилась проблема.';
    const correctedStaffReply = 'Уточните, пожалуйста, когда именно появилась проблема.';
    await staffReplyForm.locator('textarea[name="body"]').fill(originalStaffReply);
    const adminMarker = await setNoReloadMarker(page);
    await staffReplyForm.getByRole('button', { name: 'Отправить ответ', exact: true }).click();
    await expect(page.getByText('Ожидает ответа игрока', { exact: true })).toBeVisible();
    await expectNoReload(page, adminMarker);

    const staffMessageByBody = page.locator('.support-message').filter({ has: page.locator('.support-message-body', { hasText: originalStaffReply }) });
    const staffMessageId = await staffMessageByBody.getAttribute('data-message-id');
    expect(staffMessageId).not.toBeNull();
    const staffMessage = page.locator(`.support-message[data-message-id="${staffMessageId}"]`);
    await staffMessage.getByText('Изменить сообщение', { exact: true }).click();
    await staffMessage.locator('textarea[name="body"]').fill(correctedStaffReply);
    await staffMessage.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(staffMessage.locator('.support-message-body')).toHaveText(correctedStaffReply);
    await expect(page.locator('.support-message-body', { hasText: originalStaffReply })).toHaveCount(0);
    await expect(page.getByText(/Изменено \d{2}\.\d{2}\.\d{4}/)).toBeVisible();
    await page.getByText(/История изменений \(1\)/).click();
    await expect(page.locator('.support-message-revision p')).toHaveText(originalStaffReply);

    await context.clearCookies();
    await signInPlayer(page);
    await gotoWithLocalNetworkRetry(page, ticketPath);
    await expect(page.getByText('Ожидает вашего ответа', { exact: true })).toBeVisible();
    await expect(page.locator('.support-message-body', { hasText: correctedStaffReply })).toBeVisible();
    await expect(page.locator('.support-message-body', { hasText: originalStaffReply })).toHaveCount(0);
    const playerReplyForm = page.locator('[data-testid="player-reply-form"]');
    await expect(playerReplyForm.locator('textarea[name="body"]')).toHaveCSS('border-radius', '12px');
    await playerReplyForm.locator('textarea[name="body"]').fill('Проблема появилась сегодня после входа в игру.');
    const playerMarker = await setNoReloadMarker(page);
    await playerReplyForm.getByRole('button', { name: 'Отправить ответ', exact: true }).click();
    await expect(page.getByText('В работе', { exact: true })).toBeVisible();
    await expectNoReload(page, playerMarker);

    await context.clearCookies();
    await signInAdmin(page);
    await gotoWithLocalNetworkRetry(page, `/admin/extensions/support-tickets/${ticketPath.split('/').pop()}`);
    await expect(page.getByText('Ожидает вашего ответа', { exact: true })).toBeVisible();
    page.once('dialog', (dialog) => dialog.accept());
    const closeMarker = await setNoReloadMarker(page);
    await page.getByRole('button', { name: 'Закрыть обращение', exact: true }).click();
    await expect(page.getByText('Закрыто', { exact: true })).toBeVisible();
    await expectNoReload(page, closeMarker);

    await context.clearCookies();
    await signInPlayer(page);
    await gotoWithLocalNetworkRetry(page, ticketPath);
    await expect(page.getByText('Закрыто', { exact: true })).toBeVisible();
    await expect(page.locator('[data-testid="player-reply-form"]')).toHaveCount(0);
    await expect(page.getByText(/открыть его повторно может только сотрудник/i)).toBeVisible();
});

test('support ticket pages stay usable without horizontal overflow on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signInPlayer(page);
    await gotoWithLocalNetworkRetry(page, '/modules/support-tickets');

    await expect(page.locator('input[name="subject"]')).toHaveCount(0);
    await page.getByRole('button', { name: /Создать обращение/, exact: false }).click();
    await expect(page.locator('input[name="subject"]')).toBeEditable();
    await expect(page.locator('[data-testid="support-ticket-create-form"] textarea[name="body"]')).toBeEditable();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(overflow).toBe(false);
});

test('support ticket editor permissions expose dependent controls safely', async ({ page }) => {
    await signInAdmin(page);
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/support-tickets/settings');
    await expect(page.locator('[data-testid="support-settings-tabs"]')).toBeVisible();
    await expect(page.locator('[data-testid="support-cleanup-panel"]')).toHaveCount(0);

    const viewToggle = page.locator('input[type="checkbox"][name="allow_editor_view"]');
    const replyToggle = page.locator('input[type="checkbox"][name="allow_editor_reply"]');
    const noteToggle = page.locator('input[type="checkbox"][name="allow_editor_internal_notes"]');

    await expect(page.locator('label[for="allow_editor_view"] strong')).toHaveText('Разрешить просмотр обращений');
    await expect(page.locator('label[for="allow_editor_view"] strong')).toHaveCSS('display', 'block');
    await expect(page.locator('label[for="allow_editor_view"] small')).toBeVisible();
    await expect(page.locator('label[for="allow_editor_view"] small')).toHaveCSS('display', 'block');

    const viewRow = page.locator('label[for="allow_editor_view"]');
    const replyRow = page.locator('label[for="allow_editor_reply"]');
    const noteRow = page.locator('label[for="allow_editor_internal_notes"]');
    const setChecked = async (toggle, row, checked) => {
        if (await toggle.isChecked() !== checked) {
            await row.click();
        }
        if (checked) {
            await expect(toggle).toBeChecked();
        } else {
            await expect(toggle).not.toBeChecked();
        }
    };

    await setChecked(viewToggle, viewRow, false);
    await expect(replyToggle).toBeDisabled();
    await expect(noteToggle).toBeDisabled();
    await expect(replyToggle).not.toBeChecked();
    await expect(noteToggle).not.toBeChecked();

    await setChecked(viewToggle, viewRow, true);
    await expect(replyToggle).toBeEnabled();
    await expect(noteToggle).toBeEnabled();

    await setChecked(replyToggle, replyRow, true);
    await setChecked(noteToggle, noteRow, true);
    await setChecked(viewToggle, viewRow, false);
    await expect(replyToggle).toBeDisabled();
    await expect(noteToggle).toBeDisabled();
    await expect(replyToggle).not.toBeChecked();
    await expect(noteToggle).not.toBeChecked();
});

test('support cleanup tools live in a dedicated settings tab', async ({ page }) => {
    await signInAdmin(page);
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/support-tickets/settings');

    await page.getByRole('link', { name: 'Очистка базы', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/extensions\/support-tickets\/settings\?tab=cleanup$/);
    await expect(page.locator('[data-testid="support-cleanup-panel"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Сохранить настройки', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Проверить объём очистки', exact: true })).toBeVisible();
});
