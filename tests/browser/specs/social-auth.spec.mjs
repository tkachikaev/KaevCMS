import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test.use({ launchOptions: {
    ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}),
    args: ['--host-resolver-rules=MAP accounts.google.com 127.0.0.1', '--no-proxy-server'],
} });

test('social settings keep secrets private and player linking reaches the provider under CSP', async ({ page, context }, testInfo) => {
    test.setTimeout(60000);
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({ page, postPath: '/admin/login', label: 'Social settings admin login', submit: () => page.getByRole('button', { name: 'Войти в панель' }).click() });
    await gotoWithLocalNetworkRetry(page, '/admin/settings/registration');
    await page.locator('[data-social-open="social-google"]').click();
    const dialog = page.locator('#social-google');
    await expect(dialog).toBeVisible();
    await expect(page.locator('#registration-settings-form form')).toHaveCount(0);
    expect(await page.locator('.social-auth-settings').evaluate(section => Boolean(section.compareDocumentPosition(document.querySelector('[form="registration-settings-form"]')) & Node.DOCUMENT_POSITION_FOLLOWING))).toBe(true);
    await expect(dialog).toHaveCSS('opacity', '1');
    await dialog.evaluate(el => Promise.all(el.getAnimations().map(animation => animation.finished)));
    await page.screenshot({ path: testInfo.outputPath('social-settings-desktop.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(dialog.locator('[name=client_id]')).toBeVisible();
    expect(await dialog.evaluate(el => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1);
    await page.screenshot({ path: testInfo.outputPath('social-settings-mobile.png') });
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('[data-social-open="social-google"]')).toBeFocused();
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.locator('.social-auth-settings').scrollIntoViewIfNeeded();
    await page.screenshot({ path: testInfo.outputPath('social-settings-list.png') });
    await page.locator('[data-admin-appearance-toggle]').click();
    await page.locator('[data-social-open="social-google"]').click();
    await dialog.evaluate(el => Promise.all(el.getAnimations().map(animation => animation.finished)));
    await page.screenshot({ path: testInfo.outputPath('social-settings-dark.png') });
    const form = page.locator('form[action$="/registration/social/google"]');
    await form.locator('[name=client_id]').fill('isolated-browser-client');
    await form.locator('[name=client_secret]').fill('isolated-browser-secret');
    await form.locator('button[type=submit]').click();
    await page.locator('[data-social-open="social-google"]').click();
    await expect(form.locator('[name=client_secret]')).toHaveValue('');
    await page.locator('#social-google [data-social-close]').click();
    await page.locator('form[action$="/social/google/toggle"] input[type=checkbox]').click();
    await expect(page.locator('form[action$="/social/telegram/toggle"] input[type=checkbox]')).toBeDisabled();
    await page.locator('[data-social-open="social-telegram"]').click();
    const telegramForm = page.locator('form[action$="/registration/social/telegram"]');
    await telegramForm.locator('[name=client_id]').fill('123456789');
    await telegramForm.locator('[name=client_secret]').fill('isolated-telegram-secret');
    await telegramForm.locator('button[type=submit]').click();
    const telegramToggle = page.locator('form[action$="/social/telegram/toggle"] input[type=checkbox]');
    await expect(telegramToggle).toBeEnabled();
    await expect(telegramToggle).not.toBeChecked();
    const player = await context.newPage();
    await gotoWithLocalNetworkRetry(player, '/login');
    await player.locator('#login').fill(process.env.PLAYWRIGHT_PLAYER_EMAIL);
    await player.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({ page: player, postPath: '/login', label: 'Social link player login', submit: () => player.locator('form button[type=submit]').first().click() });
    for (const theme of ['luxury', 'kaev-aurelia', 'kaev-classic']) {
        await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
        const activate = page.locator(`form[action$="/account-themes/${theme}/activate"]`);
        if (await activate.count()) { await activate.getByRole('button').click(); await expect(activate).toHaveCount(0); }
        await gotoWithLocalNetworkRetry(player, '/account/security');
        for (const width of [1440, 390]) {
            await player.setViewportSize({ width, height: 900 });
            await expect(player.locator('form[action$="/social/google/link"] [name=current_password]')).toBeVisible();
            expect(await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
        }
    }
    const guest = await context.browser().newContext();
    const visitor = await guest.newPage();
    try {
        for (const theme of ['default', 'kaev-aurelia']) {
            await gotoWithLocalNetworkRetry(page, '/admin/themes');
            const activate = page.locator(`form[action$="/themes/${theme}/activate"]`);
            if (await activate.count()) { await activate.getByRole('button').click(); await expect(activate).toHaveCount(0); }
            for (const locale of ['ru', 'en']) {
                await visitor.goto(new URL('/language/'+locale, page.url()).href);
                await visitor.goto(new URL('/login', page.url()).href);
                await expect(visitor.locator('html')).toHaveAttribute('lang', locale);
                for (const width of [1440, 390]) {
                    await visitor.setViewportSize({ width, height: 900 });
                    await expect(visitor.locator('a[href$="/auth/social/google"]')).toHaveText(locale === 'ru' ? /Продолжить через Google/ : /Continue with Google/);
                    expect(await visitor.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                }
            }
        }
    } finally { await guest.close(); }
    let intercepted = 0;
    await player.route('https://accounts.google.com/**', async route => {
        const url = new URL(route.request().url());
        expect(url.searchParams.get('client_id')).toBe('isolated-browser-client');
        expect(url.searchParams.get('state')).toBeTruthy();
        expect(url.searchParams.get('code_challenge')).toBeTruthy();
        expect(url.searchParams.has('client_secret')).toBe(false);
        intercepted++;
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Isolated OAuth provider</h1>' });
    });
    await gotoWithLocalNetworkRetry(player, '/account/security');
    const link = player.locator('form[action$="/social/google/link"]');
    await link.locator('[name=current_password]').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await link.locator('button[type=submit]').click();
    await expect(player.getByRole('heading', { name: 'Isolated OAuth provider' })).toBeVisible();
    expect(intercepted).toBe(1);
    // No callback, real provider or external user credentials are used.
});
