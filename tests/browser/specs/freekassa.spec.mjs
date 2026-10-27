import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test.use({ launchOptions: {
    ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}),
    args: ['--host-resolver-rules=MAP pay.fk.money 127.0.0.1', '--no-proxy-server'],
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
    await form.locator('[name="callback_sender_ips"]').fill('198.51.100.19');
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
    await context.route('https://pay.fk.money/**', async route => {
        intercepted++;
        const target = new URL(route.request().url());
        expect(target.searchParams.get('m')).toBe('7012');
        expect(target.searchParams.get('oa')).toBe('1.00');
        expect(target.searchParams.get('currency')).toBe('RUB');
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Local dummy provider</h1>' });
    });
    // Playwright does not reroute every redirect hop. Stop at the real controller's
    // 303, verify its destination, then exercise its CSP using a direct GET form.
    // DNS is additionally pinned to loopback so a missed interception cannot pay.
    await context.route('**/providers/freekassa/test-purchase', async route => {
        const response = await route.fetch({ maxRedirects: 0 });
        expect(response.status()).toBe(303);
        const headers = { ...response.headers() };
        const destination = new URL(headers.location);
        expect(destination.origin).toBe('https://pay.fk.money');
        expect(destination.searchParams.get('s')).toMatch(/^[a-f0-9]{32}$/);
        expect(headers['content-security-policy']).toContain("form-action 'self' https://pay.fk.money;");
        delete headers.location;
        delete headers['content-length'];
        headers['content-type'] = 'text/html; charset=utf-8';
        const fields = [...destination.searchParams].map(([name, value]) => {
            expect(name).toMatch(/^[a-z_]+$/);
            expect(value).toMatch(/^[a-zA-Z0-9.\-]+$/);
            return `<input type="hidden" name="${name}" value="${value}">`;
        }).join('');
        await route.fulfill({ status: 200, headers, body: `<form method="GET" action="https://pay.fk.money/">${fields}<button>Continue locally</button></form>` });
    });
    const popupPromise = context.waitForEvent('page');
    await page.getByTestId('freekassa-test-purchase-form').getByRole('button').click();
    const popup = await popupPromise;
    await popup.getByRole('button', { name: 'Continue locally' }).click();
    await expect(popup.getByRole('heading')).toHaveText('Local dummy provider');
    expect(intercepted).toBe(1);
    await popup.close();
    await page.reload();
    await expect(page.getByTestId('freekassa-admin-test')).toContainText('1.00 RUB');
    // Restore the provider to disabled without opening any real payment page.
    await form.locator('input[type="checkbox"][name="enabled"]').uncheck();
    await form.locator('button[type="submit"]').click();
});
