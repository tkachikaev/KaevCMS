import { expect, test } from '@playwright/test';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const playerEmail = process.env.PLAYWRIGHT_PLAYER_EMAIL || 'browser-player@example.test';
const playerPassword = process.env.PLAYWRIGHT_PLAYER_PASSWORD || 'BrowserPlayerPassword123!';
const adminEmail = process.env.PLAYWRIGHT_ADMIN_EMAIL || 'browser-admin@example.test';
const adminPassword = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'BrowserPassword123!';

const signInPlayer = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/login');
    await page.locator('#login').fill(playerEmail);
    await page.locator('#password').fill(playerPassword);
    await page.locator('form').getByRole('button').click();
    await expect(page).toHaveURL(/\/account$/);
};

const signInAdmin = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(adminEmail);
    await page.locator('#password').fill(adminPassword);
    await page.getByRole('button', { name: 'Войти в панель' }).click();
    await expect(page).toHaveURL(/\/admin$/);
};

test('player and administrator complete the support ticket conversation flow', async ({ page, context }) => {
    const subject = `Browser support ${Date.now()}`;

    await signInPlayer(page);
    await page.locator('.account-nav').getByRole('link', { name: 'Техническая поддержка', exact: true }).click();
    await expect(page).toHaveURL(/\/modules\/support-tickets$/);
    await page.locator('select[name="category"]').selectOption('technical_problem');
    await page.locator('input[name="subject"]').fill(subject);
    await page.locator('textarea[name="body"]').fill('Проверка нового обращения через браузерный тест.');
    await expect(page.locator('[data-character-counter]').first()).toContainText(`${subject.length} / 120`);
    await page.getByRole('button', { name: 'Создать обращение', exact: true }).click();

    await expect(page).toHaveURL(/\/modules\/support-tickets\/\d+$/);
    const ticketPath = new URL(page.url()).pathname;
    await expect(page.getByRole('heading', { name: subject, exact: true })).toBeVisible();
    await expect(page.getByText('Новое', { exact: true })).toBeVisible();

    await context.clearCookies();
    await signInAdmin(page);
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/support-tickets');
    const ticketRow = page.locator('.support-admin-ticket-row').filter({ hasText: subject });
    await expect(ticketRow).toHaveCount(1);
    await ticketRow.getByRole('link', { name: 'Открыть обращение', exact: true }).click();
    await expect(page.getByRole('heading', { name: subject, exact: true })).toBeVisible();

    const playerReplyForm = page.locator('form[action$="/reply"]');
    const originalStaffReply = 'Уточните, пожалуйста, когда появилась проблема.';
    const correctedStaffReply = 'Уточните, пожалуйста, когда именно появилась проблема.';
    await playerReplyForm.locator('textarea[name="body"]').fill(originalStaffReply);
    await playerReplyForm.getByRole('button', { name: 'Отправить ответ', exact: true }).click();
    await expect(page.getByText('Ожидает ответа игрока', { exact: true })).toBeVisible();

    const staffMessage = page.locator('.support-message').filter({ hasText: originalStaffReply });
    await staffMessage.getByText('Изменить сообщение', { exact: true }).click();
    await staffMessage.locator('textarea[name="body"]').fill(correctedStaffReply);
    await staffMessage.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByText(correctedStaffReply, { exact: true })).toBeVisible();
    await expect(page.getByText(originalStaffReply, { exact: true })).not.toBeVisible();
    await expect(page.getByText(/Изменено \d{2}\.\d{2}\.\d{4}/)).toBeVisible();
    await page.getByText(/История изменений \(1\)/).click();
    await expect(page.getByText(originalStaffReply, { exact: true })).toBeVisible();

    await context.clearCookies();
    await signInPlayer(page);
    await gotoWithLocalNetworkRetry(page, ticketPath);
    await expect(page.getByText('Ожидает вашего ответа', { exact: true })).toBeVisible();
    await expect(page.getByText(correctedStaffReply, { exact: true })).toBeVisible();
    await expect(page.getByText(originalStaffReply, { exact: true })).toHaveCount(0);
    await page.locator('form[action$="/reply"] textarea[name="body"]').fill('Проблема появилась сегодня после входа в игру.');
    await page.getByRole('button', { name: 'Отправить ответ', exact: true }).click();
    await expect(page.getByText('В работе', { exact: true })).toBeVisible();

    await context.clearCookies();
    await signInAdmin(page);
    await gotoWithLocalNetworkRetry(page, `/admin/extensions/support-tickets/${ticketPath.split('/').pop()}`);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Закрыть обращение', exact: true }).click();
    await expect(page.getByText('Закрыто', { exact: true })).toBeVisible();

    await context.clearCookies();
    await signInPlayer(page);
    await gotoWithLocalNetworkRetry(page, ticketPath);
    await expect(page.getByText('Закрыто', { exact: true })).toBeVisible();
    await expect(page.locator('form[action$="/reply"]')).toHaveCount(0);
    await expect(page.getByText(/открыть его повторно может только сотрудник/i)).toBeVisible();
});

test('support ticket pages stay usable without horizontal overflow on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signInPlayer(page);
    await gotoWithLocalNetworkRetry(page, '/modules/support-tickets');

    await expect(page.locator('input[name="subject"]')).toBeEditable();
    await expect(page.locator('textarea[name="body"]')).toBeEditable();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(overflow).toBe(false);
});
