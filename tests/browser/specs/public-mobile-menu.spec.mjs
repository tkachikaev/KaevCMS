import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test('the language menu works in the header and the phone menu of both public themes', async ({ page, context }) => {
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

            // Desktop: one compact button in the header opens the list; the phone copy stays hidden.
            await visitor.setViewportSize({ width: 1440, height: 900 });
            await gotoWithLocalNetworkRetry(visitor, '/ru/about');
            const header = visitor.locator('.header-actions .language-menu');
            await expect(visitor.locator('.main-nav .mobile-language-menu')).toBeHidden();
            await expect(header.locator('summary')).toHaveText('RU');
            await expect(header.locator('.language-menu-list')).toBeHidden();
            await header.locator('summary').click();
            await expect(header.locator('a[aria-current="true"]')).toContainText('Русский');
            await expect(header.locator('a[hreflang="en"]')).toContainText('English');
            // An outside click and Escape close it.
            await visitor.locator('h1').click();
            await expect(header.locator('.language-menu-list')).toBeHidden();
            await header.locator('summary').click();
            await visitor.keyboard.press('Escape');
            await expect(header.locator('.language-menu-list')).toBeHidden();

            // Phone: the header actions are hidden, so the open menu carries the language menu.
            await visitor.setViewportSize({ width: 390, height: 844 });
            await gotoWithLocalNetworkRetry(visitor, '/ru/about');
            await expect(visitor.locator('.header-actions')).toBeHidden();
            await visitor.locator('.menu-toggle').click();
            const phone = visitor.locator('.main-nav .mobile-language-menu');
            await expect(phone).toBeVisible();
            const menuHeight = async () => (await visitor.locator('.main-nav').boundingBox()).height;
            const closedHeight = await menuHeight();
            await phone.locator('summary').click();
            await expect(phone.locator('.language-menu-list')).toBeVisible();
            // The list slides over the page; the menu keeps its size.
            expect(Math.abs((await menuHeight()) - closedHeight)).toBeLessThanOrEqual(1);            const selected = phone.locator('a[aria-current="true"]');
            // The selected language stays readable: its text differs from its background.
            const colours = await selected.evaluate((element) => {
                const style = getComputedStyle(element);
                return [style.color, style.backgroundColor];
            });
            expect(colours[0]).not.toBe(colours[1]);
            expect(await visitor.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);

            await phone.locator('a[hreflang="en"]').click();
            await expect(visitor).toHaveURL(/\/en\/about$/);
            await expect(visitor.locator('html')).toHaveAttribute('lang', 'en');

            await visitor.locator('.menu-toggle').click();
            await visitor.locator('.main-nav .mobile-language-menu summary').click();
            await visitor.locator('.main-nav .mobile-language-menu a[hreflang="ru"]').click();
            await expect(visitor).toHaveURL(/\/ru\/about$/);
        }
    } finally {
        await activate(originalTheme);
        await visitor.close();
    }
});
