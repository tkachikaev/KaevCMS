import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const settingsPath = '/admin/extensions/donations/providers/paypal';

async function signIn(page, role = 'ADMIN') {
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env[`PLAYWRIGHT_${role}_EMAIL`]);
    await page.locator('#password').fill(process.env[`PLAYWRIGHT_${role}_PASSWORD`]);
    await submitLogin({ page, postPath: '/admin/login', label: `PayPal ${role} login`,
        submit: () => page.locator('form[action$="/admin/login"] button[type="submit"]').click() });
    await expect(page).toHaveURL(/\/admin$/);
}

for (const width of [1440, 390]) {
    test(`PayPal settings persist with redacted secrets and localized controls at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        // No browser request may reach PayPal, including an accidental form submission.
        const remote = [];
        await page.route(/https:\/\/(?:[^/]+\.)?paypal\.com\//, route => {
            remote.push(route.request().url());
            return route.abort();
        });
        await signIn(page);
        await gotoWithLocalNetworkRetry(page, '/admin/modules');
        const enable = page.locator('[data-module-id="donations"] form[action$="/enable"]');
        if (await enable.count()) await enable.locator('button').click();
        await gotoWithLocalNetworkRetry(page, settingsPath);
        const form = page.getByTestId('paypal-config-form');
        await expect(form.locator('input[type="checkbox"][name="enable_live"]')).not.toBeChecked();
        await expect(page.locator('#paypal-test-amount')).toHaveValue('1');
        const currencies = form.locator('details').first();
        if (!await currencies.getAttribute('open').then(value => value !== null)) await currencies.locator('summary').click();
        for (const currency of ['USD', 'JPY']) {
            await form.locator(`[name="currency_codes[]"][value="${currency}"]`).check();
        }
        await page.locator('[data-provider-test-open="paypal"]').click();
        const dialog = page.locator('[data-provider-test-dialog="paypal"]');
        await expect(dialog).toBeVisible();
        const credentials = page.getByTestId('paypal-sandbox-credentials');
        if (await credentials.getAttribute('open') === null) await credentials.locator('summary').click();
        await page.locator('#paypal-sandbox-id').fill(`QaBrowserClient${width}`);
        await page.locator('#paypal-sandbox-secret').fill(`QaBrowserSecret${width}`);
        await page.locator('#paypal-sandbox-webhook').fill(`QaBrowserHook${width}`);
        await dialog.locator('button[form="paypal-settings-form"]').click();
        await expect(page.locator('#paypal-sandbox-id')).toHaveValue(`QaBrowserClient${width}`);
        for (const locale of ['en', 'ru']) {
            await expect(dialog).toBeVisible();
            await dialog.locator('[data-provider-test-close]').click();
            await expect(dialog).not.toBeVisible();
            await page.locator('.admin-language-menu > summary').click();
            await page.locator(`.admin-language-menu button[lang="${locale}"]`).click();
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expect(page.getByTestId('donations-paypal-settings')).toBeVisible();
            await expect(form.getByRole('button', { name: locale === 'ru' ? 'Сохранить настройки' : 'Save settings', exact: true })).toBeVisible();
            await page.locator('[data-provider-test-open="paypal"]').click();
            await expect(dialog).toBeVisible();
            await expect(page.locator('#paypal-sandbox-secret')).toHaveValue('');
            await expect(page.locator('#paypal-sandbox-webhook')).toHaveValue('');
            expect(await page.content()).not.toContain(`QaBrowserSecret${width}`);
            expect(await page.content()).not.toContain(`QaBrowserHook${width}`);
            await expect(form.locator('input[type="checkbox"][name="enable_live"]')).not.toBeChecked();
            await expect(page.locator('#paypal-webhook-url')).toHaveValue(/\/payments\/paypal\/notify$/);
            expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
            await page.reload();
        }
        // A blank secret must preserve the saved credential across another save.
        if (await credentials.getAttribute('open') === null) await credentials.locator('summary').click();
        await dialog.locator('button[form="paypal-settings-form"]').click();
        await expect(page.getByTestId('paypal-admin-sandbox').locator('button[type="submit"]')).toBeEnabled();
        expect(remote).toEqual([]);
        await page.waitForFunction(() => [...document.querySelectorAll('[data-provider-test-dialog]')].every(dialog => !dialog.getAnimations({ subtree: true }).some(animation => animation.playState === 'running')));
        await expect(dialog).toHaveCSS('flex-direction', 'column');
        await expect(dialog).toHaveCSS('opacity', '1');
        const regions = await dialog.evaluate(element => ({ header: element.querySelector('.donations-provider-test-header').getBoundingClientRect().bottom, body: element.querySelector('.donations-provider-test-body').getBoundingClientRect().top }));
        expect(regions.body).toBeGreaterThanOrEqual(regions.header - 1);
        await page.screenshot({ path: `storage/framework/testing/paypal-test-dialog-${width}.png` });
        await dialog.locator('[data-provider-test-close]').click();
        await page.waitForFunction(() => [...document.querySelectorAll('[data-provider-test-dialog]')].every(dialog => !dialog.getAnimations({ subtree: true }).some(animation => animation.playState === 'running')));
        await expect(dialog).toHaveCSS('overlay', 'none');
        await page.screenshot({ path: `storage/framework/testing/paypal-settings-${width}.png`, fullPage: true });
        await page.locator('[data-provider-test-open="paypal"]').click();
        await expect(dialog).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        await page.waitForFunction(() => typeof window.KaevCMSAdminAppearance?.apply === 'function');
        await page.evaluate(() => window.KaevCMSAdminAppearance.apply('dark'));
        await expect(page.locator('html')).toHaveAttribute('data-admin-color-scheme', 'dark');
        await expect(page.locator('html')).not.toHaveClass(/admin-theme-changing/);
        await page.locator('[data-provider-test-open="paypal"]').click();
        await expect(dialog).toHaveCSS('opacity', '1');
        await expect(dialog).toHaveCSS('background-color', 'rgb(22, 23, 26)');
        await page.screenshot({ path: `storage/framework/testing/paypal-test-dialog-dark-${width}.png` });
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
    });
}

test('PayPal auditor view is read-only and settings POST requires CSRF', async ({ page, browser }) => {
    await signIn(page, 'AUDITOR');
    await gotoWithLocalNetworkRetry(page, settingsPath);
    await expect(page.getByTestId('donations-paypal-settings')).toBeVisible();
    const form = page.getByTestId('paypal-config-form');
    await expect(page.locator('#paypal-sandbox-id')).toBeDisabled();
    await expect(form.locator('button[type="submit"]')).toHaveCount(0);
    const context = await browser.newContext();
    const owner = await context.newPage();
    await signIn(owner);
    const response = await context.request.post(settingsPath, {
        form: { _method: 'PUT', 'currency_codes[]': 'USD' }, maxRedirects: 0,
    });
    expect(response.status()).toBe(419);
    await context.close();
});

for (const width of [1440, 390]) {
    test(`Payment provider cards share the same layout with local brand icons at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await signIn(page);
        await gotoWithLocalNetworkRetry(page, '/admin/modules');
        const enable = page.locator('[data-module-id="donations"] form[action$="/enable"]');
        if (await enable.count()) await enable.locator('button').click();
        await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations/providers');
        const cards = page.locator('.donations-provider-card');
        await expect(cards).toHaveCount(3);
        for (const locale of ['ru', 'en']) {
            if (await page.locator('html').getAttribute('lang') !== locale) {
                await page.locator('.admin-language-menu > summary').click();
                await page.locator(`.admin-language-menu button[lang="${locale}"]`).click();
            }
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            for (const scheme of ['light', 'dark']) {
                await page.waitForFunction(() => typeof window.KaevCMSAdminAppearance?.apply === 'function');
                await page.evaluate(scheme => window.KaevCMSAdminAppearance.apply(scheme), scheme);
                await expect(page.locator('html')).toHaveAttribute('data-admin-color-scheme', scheme);
                await expect(page.locator('html')).not.toHaveClass(/admin-theme-changing/);
                for (const [code, file, description] of [
                    ['yoomoney', 'yoomoney.svg', locale === 'ru' ? 'Кошелёк ЮMoney и банковские карты' : 'YooMoney wallet and bank cards'],
                    ['freekassa', 'freekassa.png', locale === 'ru' ? 'Банковские карты и электронные кошельки' : 'Bank cards and electronic wallets'],
                    ['paypal', 'paypal.svg', locale === 'ru' ? 'Кошелёк PayPal и банковские карты' : 'PayPal wallet and bank cards'],
                ]) {
                    const card = page.locator(`[data-provider="${code}"]`);
                    await expect(card.locator('.donations-provider-description')).toHaveText(description);
                    const logo = card.locator('img');
                    await expect(logo).toHaveAttribute('src', new RegExp(`/assets/modules/${file.replace('.', '\\.')}$`));
                    await expect.poll(() => logo.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
                    expect(new URL(await logo.getAttribute('src')).origin).toBe(new URL(page.url()).origin);
                    await expect(card.getByRole('link', { name: locale === 'ru' ? 'Настроить' : 'Configure', exact: true })).toBeVisible();
                }
                const layout = await cards.evaluateAll(items => items.map(item => ({
                    top: item.getBoundingClientRect().top,
                    height: item.getBoundingClientRect().height,
                    button: item.querySelector('.button').getBoundingClientRect().top,
                    badge: item.querySelector('.donations-provider-badges').getBoundingClientRect().top,
                })));
                if (width > 1000) {
                    for (const dimension of ['top', 'height', 'button', 'badge']) {
                        expect(Math.max(...layout.map(item => item[dimension])) - Math.min(...layout.map(item => item[dimension]))).toBeLessThanOrEqual(1);
                    }
                } else {
                    expect(layout[1].top).toBeGreaterThan(layout[0].top + layout[0].height);
                    expect(layout[2].top).toBeGreaterThan(layout[1].top + layout[1].height);
                }
                expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                await page.screenshot({ path: `storage/framework/testing/provider-cards-${width}-${locale}-${scheme}.png`, fullPage: true });
            }
        }
    });
}
