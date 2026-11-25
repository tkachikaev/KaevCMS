import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test('account header follows navigation without duplicate page headings in both themes and mobile layouts', async ({ page, context }) => {
    test.setTimeout(90_000);
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({
        page, postPath: '/admin/login', label: 'Account header administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    const player = await context.newPage();
    await gotoWithLocalNetworkRetry(player, '/login');
    await player.locator('#login').fill(process.env.PLAYWRIGHT_PLAYER_EMAIL);
    await player.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({
        page: player, postPath: '/login', label: 'Account header player login',
        submit: () => player.locator('form').getByRole('button').click(),
    });
    for (const theme of ['luxury', 'kaev-aurelia']) {
        await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
        const activate = page.locator(`form[action$="/account-themes/${theme}/activate"]`);
        if (await activate.count()) {
            await activate.getByRole('button').click();
            await expect(activate).toHaveCount(0);
        }
        for (const width of [1440, 390, 320]) {
            await player.setViewportSize({ width, height: 900 });
            await gotoWithLocalNetworkRetry(player, '/account');
            await expect(player.locator('.account-topbar h1')).toHaveText('Обзор');
            const description = player.locator('.account-topbar .account-page-description');
            await expect(description).toBeVisible();
            let previousDescription = await description.innerText();
            for (const [path, title] of [
                ['/account/wallet', 'Мой кошелёк'],
                ['/account/characters', 'Мои персонажи'],
                ['/account/game-accounts', 'Мои аккаунты'],
                ['/account/web-inventory', 'Веб-инвентарь'],
                ['/modules/promo-codes', 'Промокоды'],
                ['/modules/daily-rewards', 'Ежедневные награды'],
                ['/modules/support-tickets', 'Техническая поддержка'],
                ['/account', 'Обзор'],
            ]) {
                if (width < 981) await player.locator('[data-account-sidebar-toggle]').click();
                await player.locator(`.account-nav a[href$="${path}"]`).click();
                const heading = player.locator('.account-topbar h1');
                await expect(heading).toHaveText(title);
                await expect(heading).toBeInViewport();
                await expect(player.locator('h1')).toHaveCount(1);
                await expect(description).toBeInViewport();
                await expect(description).not.toHaveText(previousDescription);
                previousDescription = await description.innerText();
                expect(previousDescription.trim()).not.toBe('');
                await expect(player.locator('html')).not.toHaveClass(/account-sidebar-open/);
                expect(await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
            }
            await player.locator('.account-profile-menu-topbar > summary').click();
            await player.locator('.account-profile-dropdown').getByRole('link', { name: 'Настройки аккаунта', exact: true }).click();
            await expect(player.locator('.account-topbar h1')).toHaveText('Настройки аккаунта');
        }
    }
    await player.close();
});
