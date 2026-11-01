import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test('referrals configure, retain encrypted invitation and render bundled account themes', async ({ page, context }, testInfo) => {
    test.setTimeout(60000);
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({ page, postPath: '/admin/login', label: 'Referral admin', submit: () => page.getByRole('button', { name: 'Войти в панель' }).click() });
    await page.goto('/admin/modules');
    const enable = page.locator('[data-module-id="referrals"] form[action$="/enable"]');
    if (await enable.count()) { await enable.locator('button').click(); await expect(enable).toHaveCount(0); }
    await page.goto('/admin/extensions/referrals');
    const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const serverUrl = await page.locator('a[href*="/referrals/servers/"]').first().getAttribute('href');
    expect(serverUrl).toBeTruthy();
    const save = async (url, values) => {
        const response = await page.request.post(url, { form: { _token: token, ...values } });
        expect(response.ok()).toBe(true);
    };
    await save('/admin/settings/registration', { _method: 'PUT', registration_enabled: '1', email_verification_required: '0' });
    await save(serverUrl, { _method: 'PUT', enabled: '1', checks_paused: '0', inviter_rewards_enabled: '1', invitee_rewards_enabled: '1', max_rewarded_invitees: '' });
    await save(serverUrl + '/rules', { audience: 'inviter', level: '20', item_id: '57', amount: '3' });
    await save('/admin/extensions/referrals/settings', { _method: 'PUT', enabled: '1', invite_ttl_days: '7' });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    for (const locale of ['ru', 'en']) {
        await save('/admin/language/' + locale, {});
        for (const url of ['/admin/extensions/referrals', serverUrl, '/admin/extensions/referrals/invites', '/admin/extensions/referrals/grants']) {
            await page.goto(url);
            await expect(page.locator('h1')).toBeVisible();
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            for (const scheme of ['light', 'dark']) {
                await page.evaluate(value => document.documentElement.dataset.adminColorScheme = value, scheme);
                for (const width of [1440, 390]) {
                    await page.setViewportSize({ width, height: 1000 });
                    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                    if (url === '/admin/extensions/referrals' && locale === 'ru') await page.screenshot({path:testInfo.outputPath(`referrals-admin-${scheme}-${width}.png`)});
                }
            }
        }
    }
    const player = await context.newPage();
    await player.emulateMedia({ reducedMotion: 'reduce' });
    await player.goto('/login');
    await player.locator('#login').fill(process.env.PLAYWRIGHT_PLAYER_EMAIL);
    await player.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({ page: player, postPath: '/login', label: 'Referral player', submit: () => player.locator('form button[type=submit]').first().click() });
    let invitation;
    for (const theme of ['luxury', 'kaev-aurelia', 'kaev-classic']) {
        await page.goto('/admin/account-themes');
        const activate = page.locator(`form[action$="/account-themes/${theme}/activate"]`);
        if (await activate.count()) { await activate.locator('button').click(); await expect(activate).toHaveCount(0); }
        for (const locale of ['ru', 'en']) {
            await player.goto('/language/' + locale);
            await player.goto('/modules/referrals');
            await expect(player.locator('#referral-link')).toBeVisible();
            await expect(player.locator('#referral-link')).toHaveCSS('border-top-style', 'solid');
            expect(await player.locator('#referral-link').evaluate(el => el.clientHeight)).toBeGreaterThan(30);
            invitation = await player.locator('#referral-link').inputValue();
            for (const width of [1440, 768, 390, 320]) {
                await player.setViewportSize({ width, height: 1000 });
                expect(await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                if (locale === 'ru' && [1440, 390].includes(width)) await player.screenshot({ path: testInfo.outputPath(`referrals-${theme}-${width}.png`) });
            }
            if (theme === 'kaev-classic') {
                await player.locator('[data-account-scheme-toggle]').click();
                await expect(player.locator('html')).toHaveAttribute('data-account-scheme', 'dark');
                await player.screenshot({ path: testInfo.outputPath(`referrals-dark-${locale}.png`) });
                await player.locator('[data-account-scheme-toggle]').click();
            }
        }
    }
    const guest = await context.browser().newContext();
    try {
        const visitor = await guest.newPage();
        await visitor.goto(invitation);
        const cookie = (await guest.cookies()).find(c => c.name === 'kaev_referral_invitation');
        expect(cookie.httpOnly).toBe(true);
        expect(cookie.value).not.toContain(new URL(invitation).pathname.split('/').at(-1));
        const registrationPage = await visitor.goto('/register');
        expect(registrationPage.status()).toBe(200);
        await visitor.screenshot({path:testInfo.outputPath('registration-before.png')});
        await visitor.locator('[name=name]').fill('referralbrowser');
        await visitor.locator('[name=email]').fill('referralbrowser@example.test');
        await visitor.locator('[name=password]').fill('BrowserReferral123!');
        await visitor.locator('[name=password_confirmation]').fill('BrowserReferral123!');
        expect(await visitor.locator('form').filter({has: visitor.locator('#name')}).evaluate(f=>f.checkValidity())).toBe(true);
        await submitLogin({ page: visitor, postPath: '/register', label: 'Referral registration', submit: () => visitor.locator('form').filter({has: visitor.locator('#name')}).locator('button[type=submit]').click() });
        await page.goto('/admin/extensions/referrals/invites');
        await expect(page.locator('form[action$="/freeze"]')).toHaveCount(1);
    } finally { await guest.close(); }
});
