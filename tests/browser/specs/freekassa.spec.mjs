import { createHash } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test.use({ launchOptions: {
    ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}),
    args: ['--host-resolver-rules=MAP pay.fk.money 127.0.0.1, MAP checkout.example.test 127.0.0.1', '--no-proxy-server'],
} });

test('FreeKassa settings persist, admin checkout redirects correctly and CSP permits the isolated provider form', async ({ page, context }) => {
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({ page, postPath: '/admin/login', label: 'FreeKassa administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click() });
    await gotoWithLocalNetworkRetry(page, '/admin/modules');
    const enable = page.locator('[data-module-id="donations"] form[action$="/enable"]');
    if (await enable.count()) await enable.getByRole('button').click();
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations');
    const workspace = page.getByTestId('donations-workspace');
    const rub = page.getByTestId('donations-rate-RUB');
    if (!await rub.count()) {
        await workspace.locator('[data-donations-currency]').selectOption('RUB');
        await workspace.getByRole('button', { name: 'Добавить валюту', exact: true }).click();
    }
    await rub.locator('[data-donations-price]').fill('1.00');
    await rub.locator('input[type="checkbox"]').check();
    await workspace.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByText('Настройки и валюты сохранены.', { exact: true })).toBeVisible();
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations/providers/freekassa');
    const form = page.locator('form[action$="/providers/freekassa"]');
    await form.locator('[name="currency_codes[]"][value="RUB"]').check();
    await form.locator('[name="credentials[merchant_id]"]').fill('7012');
    await form.locator('[name="credentials[secret_1]"]').fill('browser-dummy-checkout-secret');
    await form.locator('[name="credentials[secret_2]"]').fill('browser-dummy-callback-secret');
    await form.locator('input[type="checkbox"][name="enabled"]').check();
    await form.locator('[name="mode"]').selectOption('test');
    await page.getByTestId('freekassa-security').locator('summary').first().click();
    await form.locator('[name="callback_sender_ips"]').fill('127.0.0.1');
    await form.locator('button[type="submit"]').click();
    await expect(form.locator('[name="credentials[secret_1]"]')).toHaveValue('');
    await page.reload();
    await expect(form.locator('[name="credentials[merchant_id]"]')).toHaveValue('7012');
    await expect(form.locator('input[type="checkbox"][name="enabled"]')).toBeChecked();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
    }
    const address = page.locator('[data-donations-select-copy]');
    await address.click();
    expect(await address.evaluate(input => input.selectionEnd - input.selectionStart)).toBe((await address.inputValue()).length);
    let intercepted = 0;
    let testReference;
    await context.route('https://pay.fk.money/**', async route => {
        intercepted++;
        const target = new URL(route.request().url());
        expect(target.searchParams.get('m')).toBe('7012');
        expect(target.searchParams.get('oa')).toBe('1.00');
        expect(target.searchParams.get('currency')).toBe('RUB');
        testReference = target.searchParams.get('o');
        await route.fulfill({ status: 301, headers: { location: 'https://checkout.example.test/payment' }, body: '' });
    });
    await context.route('https://checkout.example.test/**', route => route.fulfill({
        status: 200, contentType: 'text/html', body: '<h1>Local dummy provider</h1>',
    }));
    // Exercise the real POST/303/GET hand-off without replacing the controller response.
    await page.screenshot({ path: 'storage/framework/testing/freekassa-settings.png', fullPage: true });
    await page.locator('[data-provider-test-open="freekassa"]').click();
    await expect(page.locator('[data-provider-test-dialog="freekassa"]')).toBeVisible();
    // A temporary status failure must recover without a reload or a second payment.
    let statusFailures = 0;
    await page.route('**/providers/freekassa?test_status=1', async route => {
        if (statusFailures++ === 0) await route.fulfill({ status: 503, contentType: 'application/json', body: '{}' });
        else await route.continue();
    });
    const popupPromise = context.waitForEvent('page');
    await page.getByTestId('freekassa-test-purchase-form').getByRole('button').click();
    const popup = await popupPromise;
    await expect(popup).toHaveURL(/\/providers\/freekassa$/);
    const continuation = popup.getByTestId('freekassa-test-continue');
    await expect(continuation).toHaveAttribute('href', /^https:\/\/pay\.fk\.money\/\?/);
    await continuation.click();
    await expect(popup.getByRole('heading')).toHaveText('Local dummy provider');
    expect(intercepted).toBe(1);
    await popup.close();
    const callback = await context.request.post('/payments/freekassa/notify', { form: {
        MERCHANT_ID: '7012', AMOUNT: '1.00', intid: '0', CUR_ID: '26', MERCHANT_ORDER_ID: testReference,
        SIGN: createHash('md5').update(`7012:1.00:browser-dummy-callback-secret:${testReference}`).digest('hex'),
    } });
    expect(callback.status()).toBe(200);
    expect(await callback.text()).toBe('YES');
    await page.bringToFront();
    await expect(page.locator('[data-freekassa-result]')).toHaveAttribute('data-state', 'verified', { timeout: 10000 });
    expect(statusFailures).toBeGreaterThan(1);
    await expect(page.locator('[data-freekassa-state="verified"]')).toContainText('Тестовая оплата подтверждена');
    await page.locator('[data-freekassa-result]').screenshot({ path: 'storage/framework/testing/freekassa-test-success-panel.png' });
    await page.screenshot({ path: 'storage/framework/testing/freekassa-test-success.png', fullPage: true });
    await page.reload();
    await expect(page.getByTestId('freekassa-admin-test')).toContainText('1.00 RUB');
    await page.getByTestId('freekassa-admin-test').getByText('Последние тестовые запросы', { exact: true }).click();
    await page.getByTestId('freekassa-clear-tests').getByRole('button').click();
    await expect(page.getByTestId('freekassa-admin-test')).toContainText('Тестовых заказов пока нет.');
    await expect(page.locator('[data-freekassa-result]')).toHaveAttribute('data-state', 'none');
    await page.locator('[data-provider-test-close]').click();
    // Restore the provider to disabled without opening any real payment page.
    await form.locator('input[type="checkbox"][name="enabled"]').uncheck();
    await form.locator('button[type="submit"]').click();
});
