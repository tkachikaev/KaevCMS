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
    await save(serverUrl + '/rules', { audience: 'invitee', level: '10', item_id: '57', amount: '1' });
    await page.goto('/admin/extensions/referrals');
    const otherServerUrl = await page.locator('a[href*="/referrals/servers/"]').nth(1).getAttribute('href');
    await save(otherServerUrl, { _method: 'PUT', enabled: '1', checks_paused: '0', inviter_rewards_enabled: '1', invitee_rewards_enabled: '1', max_rewarded_invitees: '' });
    await save(otherServerUrl + '/rules', { audience: 'inviter', level: '20', item_id: '57', amount: '5' });
    await save('/admin/extensions/referrals/settings', { _method: 'PUT', enabled: '1', invite_ttl_days: '7' });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    for (const locale of ['ru', 'en']) {
        await save('/admin/language/' + locale, {});
        for (const url of ['/admin/extensions/referrals', serverUrl, '/admin/extensions/referrals/invites', '/admin/extensions/referrals/grants']) {
            await page.goto(url);
            await expect(page.locator('h1')).toBeVisible();
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            if (url === serverUrl) {
                await page.evaluate(() => window.referralDocumentMarker = 'same-document');
                // Both reward sides are shown side by side; the item ID resolves to its name and icon.
                await expect(page.locator('#referral-panel-inviter')).toBeVisible();
                await expect(page.locator('#referral-panel-invitee')).toBeVisible();
                await page.locator('#ref_inviter_item_id').fill('57');
                await expect(page.locator('#referral-panel-inviter [data-referrals-item-name]')).toHaveText(locale === 'ru' ? 'Адена' : 'Adena');
                await page.locator('#ref_inviter_item_id').fill('');
                expect(await page.evaluate(() => window.referralDocumentMarker)).toBe('same-document');
                if (locale === 'ru') {
                    const form = page.locator('#referral-panel-inviter [data-referrals-add-rule]');
                    await form.locator('[name=level]').fill('55');
                    await form.locator('[name=item_id]').fill('57');
                    await form.locator('[name=amount]').fill('100');
                    const add = form.locator('button[type=submit]');
                    await add.scrollIntoViewIfNeeded();
                    const formTop = await form.evaluate(el => el.getBoundingClientRect().top);
                    const before = await page.locator('#referral-panel-inviter [data-referral-rule-id]').count();
                    await add.click();
                    await expect(page.locator('#referral-panel-inviter [data-referral-rule-id]')).toHaveCount(before + 1);
                    await expect(form.locator('[name=level]')).toHaveValue('');
                    await expect(form.locator('[data-referrals-form-status]')).toBeVisible();
                    expect(await page.evaluate(() => window.referralDocumentMarker)).toBe('same-document');
                    expect(Math.abs(await form.evaluate(el => el.getBoundingClientRect().top) - formTop)).toBeLessThan(3);
                    await form.locator('[name=level]').fill('55');
                    await form.locator('[name=item_id]').fill('57');
                    await form.locator('[name=amount]').fill('100');
                    await add.click();
                    await expect(form.locator('[data-referrals-form-error]')).toBeVisible();
                    await expect(page.locator('#referral-panel-inviter [data-referral-rule-id]')).toHaveCount(before + 1);
                    expect(await page.evaluate(() => window.referralDocumentMarker)).toBe('same-document');
                }
            }
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
            await expect(player.locator('#referral-link')).toHaveCSS('border-bottom-style', 'solid');
            expect(await player.locator('#referral-link').evaluate(el => el.clientHeight)).toBeGreaterThan(30);
            invitation = await player.locator('#referral-link').inputValue();
            await expect(player.locator('[data-account-copy-toast]')).toBeHidden();
            if (theme === 'luxury' && locale === 'ru') {
                await context.grantPermissions(['clipboard-read', 'clipboard-write']);
                await player.locator('[data-account-copy-target="referral-link"]').click();
                await expect(player.locator('[data-account-copy-toast]')).toBeVisible();
                expect(await player.evaluate(() => navigator.clipboard.readText())).toBe(invitation);
                await expect(player.locator('[data-account-copy-toast]')).toBeHidden({timeout: 4000});
                await expect(player.locator('[data-account-copy-toast]')).toHaveText('');
            }
            for (const width of [1440, 768, 390, 320]) {
                await player.setViewportSize({ width, height: 1000 });
                expect(await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                if (locale === 'ru' && [1440, 390].includes(width)) await player.screenshot({ path: testInfo.outputPath(`referrals-${theme}-${width}.png`) });
            }
            for (const tab of ['progress', 'history']) {
                await player.locator(`[data-referral-tabs] a[href*="tab=${tab}"]`).click();
                await expect(player.locator(`[data-referral-tabs] a[href*="tab=${tab}"]`)).toHaveAttribute('aria-current', 'page');
                await expect(player.locator('#referral-link')).toHaveCount(0);
                if (tab === 'history') {
                    await player.locator('[data-referral-servers] a[href*="server=all"]').click();
                    await expect(player).toHaveURL(/server=all/);
                    await expect(player.locator('[data-referral-servers] a[href*="server=all"]')).toHaveAttribute('aria-current', 'page');
                }
                await player.reload();
                await expect(player.locator(`[data-referral-tabs] a[href*="tab=${tab}"]`)).toHaveAttribute('aria-current', 'page');
                for (const width of [1440, 390, 320]) {
                    await player.setViewportSize({ width, height: 1000 });
                    expect(await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                    if (locale === 'ru' && width !== 320) await player.screenshot({path: testInfo.outputPath(`referrals-${theme}-${tab}-${width}.png`)});
                }
            }
            await player.locator('[data-referral-tabs] a[href*="tab=invite"]').click();
            await expect(player.locator('#referral-link')).toBeVisible();
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
        const changeGuestLanguage = async (locale) => {
            const menu = visitor.locator('[data-language-menu]:visible').first();
            await menu.locator('summary').click();
            await menu.locator(`a[hreflang="${locale}"]`).click();
            await expect(visitor.locator('html')).toHaveAttribute('lang', locale);
            const retained = (await guest.cookies()).find(c => c.name === 'kaev_referral_invitation');
            expect(retained).toBeTruthy();
            expect(retained.httpOnly).toBe(true);
            expect(retained.expires).toBeGreaterThan(Date.now() / 1000 + 6 * 86400);
        };
        await changeGuestLanguage('en');
        await changeGuestLanguage('ru');
        await visitor.locator('.auth-card a.button').click();
        await expect(visitor.locator('#name')).toBeVisible();
        await changeGuestLanguage('en');
        await visitor.reload();
        await expect(visitor.locator('html')).toHaveAttribute('lang', 'en');
        await visitor.screenshot({path:testInfo.outputPath('registration-before.png')});
        await visitor.locator('[name=name]').fill('referralbrowser');
        await visitor.locator('[name=email]').fill('referralbrowser@example.test');
        await visitor.locator('[name=password]').fill('BrowserReferral123!');
        await visitor.locator('[name=password_confirmation]').fill('BrowserReferral123!');
        expect(await visitor.locator('form').filter({has: visitor.locator('#name')}).evaluate(f=>f.checkValidity())).toBe(true);
        const registrationPath = new URL(await visitor.locator('form').filter({has: visitor.locator('#name')}).getAttribute('action'), visitor.url()).pathname;
        await submitLogin({ page: visitor, postPath: registrationPath, label: 'Referral registration after language changes', submit: () => visitor.locator('form').filter({has: visitor.locator('#name')}).locator('button[type=submit]').click() });
        expect((await guest.cookies()).find(c => c.name === 'kaev_referral_invitation')).toBeUndefined();
        await page.goto('/admin/extensions/referrals/invites');
        await expect(page.locator('form[action$="/freeze"]')).toHaveCount(1);
    } finally { await guest.close(); }
});
