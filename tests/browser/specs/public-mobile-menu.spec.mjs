import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test('the phone menu offers the language choice in both public themes', async ({ page, context }) => {
    test.setTimeout(60_000);
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({ page, postPath: '/admin/login', label: 'Public menu administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click() });
    await gotoWithLocalNetworkRetry(page, '/admin/themes');
    const originalTheme = await page.locator('.admin-overview-stat strong').first().innerText();
    const activate = async (theme) => {
        await gotoWithLocalNetworkRetry(page, '/admin/themes');
        const form = page.locator(`form[action$="/themes/${theme}/activate"]`);
        if (await form.count()) {
            await form.getByRole('button').click();
            await expect(form).toHaveCount(0);
        }
    };

    const visitor = await context.newPage();
    try {
        for (const theme of ['default', 'kaev-aurelia']) {
            await activate(theme);

            // Desktop: the switcher sits in the header; the menu copy stays hidden.
            await visitor.setViewportSize({ width: 1440, height: 900 });
            await gotoWithLocalNetworkRetry(visitor, '/ru/about');
            await expect(visitor.locator('.header-actions .language-switcher')).toBeVisible();
            await expect(visitor.locator('.main-nav .mobile-language-switcher')).toBeHidden();

            // Phone: the header actions are hidden, so the open menu carries RU / EN.
            await visitor.setViewportSize({ width: 390, height: 844 });
            await gotoWithLocalNetworkRetry(visitor, '/ru/about');
            await expect(visitor.locator('.header-actions')).toBeHidden();
            await visitor.locator('.menu-toggle').click();
            const switcher = visitor.locator('.main-nav .mobile-language-switcher');
            await expect(switcher).toBeVisible();
            await expect(switcher.locator('a.active')).toHaveText('RU');
            expect(await visitor.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);

            await switcher.getByRole('link', { name: 'EN', exact: true }).click();
            await expect(visitor).toHaveURL(/\/en\/about$/);
            await expect(visitor.locator('html')).toHaveAttribute('lang', 'en');

            await visitor.locator('.menu-toggle').click();
            await visitor.locator('.main-nav .mobile-language-switcher').getByRole('link', { name: 'RU', exact: true }).click();
            await expect(visitor).toHaveURL(/\/ru\/about$/);
        }
    } finally {
        await activate(originalTheme);
        await visitor.close();
    }
});
