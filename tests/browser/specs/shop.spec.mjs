import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test('shop activates through administration, buys once and renders all account themes', async ({ page, context }, testInfo) => {
    test.setTimeout(150000);
    const shopPlayerEmail = 'browser-shop-player@example.test';
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({ page, postPath: '/admin/login', label: 'Shop administrator', submit: () => page.locator('form[action$="/admin/login"] button[type=submit]').click() });
    await page.goto('/admin/modules');
    const enable = page.locator('[data-module-id="shop"] form[action$="/enable"]');
    if (await enable.count()) { await enable.locator('button').click(); await expect(enable).toHaveCount(0); }
    await page.goto('/admin/extensions/shop');
    const save = async (url, data) => {
        const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
        const response = await page.request.post(url, { form: { _token: token, ...data } });
        expect(response.ok(), `${url}: HTTP ${response.status()}`).toBe(true);
        expect(await response.text()).not.toContain('notice notice-error');
        return response;
    };
    const serverUrl = await page.locator('a[href*="/shop/servers/"]').first().getAttribute('href');
    const serverId = serverUrl.split('/').at(-1);
    await save(serverUrl + '/tabs', { name_ru: 'Снаряжение', name_en: 'Equipment', sort_order: 0, is_active: 1 });
    await page.goto(serverUrl);
    const sectionLink = page.locator('a.admin-tab[href*="section="]').first();
    await expect(sectionLink).toBeVisible();
    const sectionId = new URL(await sectionLink.getAttribute('href'), page.url()).searchParams.get('section');
    await page.locator('[data-shop-open="shop-product-new"]').click();
    const itemDialog = page.locator('#shop-product-new');
    await expect(itemDialog).toBeVisible();
    await itemDialog.locator('[name=name_ru]').fill('Адена для нового путешествия');
    await itemDialog.locator('[name=name_en]').fill('Adena for a new adventure');
    await itemDialog.locator('[name=description_ru]').fill('Монеты для начала путешествия');
    await itemDialog.locator('[name=price_coins]').fill('25');
    await itemDialog.locator('[name="items[0][item_id]"]').fill('57');
    await itemDialog.locator('[name="items[0][quantity]"]').fill('1000');
    await itemDialog.locator('button[type=submit]').first().click();
    await expect(page.locator('[data-shop-view-panel="cards"] [data-shop-open^="shop-product-"]')).toHaveCount(1);

    await page.locator('[data-shop-open="shop-product-new"]').click();
    const bundleDialog = page.locator('#shop-product-new');
    await bundleDialog.locator('[name=name_ru]').fill('Комплект путешественника');
    await bundleDialog.locator('[name=name_en]').fill('Traveller bundle');
    await bundleDialog.locator('[name=price_coins]').fill('25');
    await bundleDialog.locator('[name="items[0][item_id]"]').fill('57');
    await bundleDialog.locator('[name="items[0][quantity]"]').fill('200');
    await bundleDialog.locator('[data-shop-add-item]').click();
    await bundleDialog.locator('[name="items[1][item_id]"]').fill('4037');
    await bundleDialog.locator('[name="items[1][quantity]"]').fill('3');
    await bundleDialog.locator('button[type=submit]').first().click();
    await expect(page.locator('[data-shop-view-panel="cards"] [data-shop-open^="shop-product-"]')).toHaveCount(2);
    await page.locator('[data-shop-view-toggle="table"]').click();
    await expect(page.locator('[data-shop-view-panel="table"]')).toBeVisible();
    await expect(page.locator('[data-shop-view-panel="cards"]')).toBeHidden();
    await expect(page.locator('[data-shop-view-panel="table"] tbody tr')).toHaveCount(2);
    await page.locator('[data-shop-view-toggle="cards"]').click();
    await expect(page.locator('[data-shop-view-panel="cards"] .shop-product-card')).toHaveCount(2);
    await expect(page.locator('[data-shop-view-panel="cards"] [data-shop-art-count="2"]')).toHaveCount(1);
    expect(sectionId).toBeTruthy();
    const player = await context.newPage();
    await player.emulateMedia({ reducedMotion: 'reduce' });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await player.goto('/login');
    await player.locator('#login').fill(shopPlayerEmail);
    await player.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({ page: player, postPath: '/login', label: 'Shop player', submit: () => player.locator('form button[type=submit]').first().click() });
    await player.goto('/modules/shop');
    await expect(player.locator('form[action$="/shop/checkout"]')).toHaveCount(0);
    await page.goto(`/admin/wallets?q=${encodeURIComponent(shopPlayerEmail)}`);
    await page.getByTestId('admin-wallet-row').getByRole('link', { name: 'Подробнее' }).click();
    const adjustment = page.getByTestId('admin-wallet-adjustment-form');
    await adjustment.locator('[name=amount]').fill('100');
    await adjustment.locator('[name=reason]').fill('Shop isolated browser acceptance');
    await adjustment.locator('[name=confirm_adjustment]').check();
    await adjustment.getByRole('button').click();
    await save('/admin/extensions/shop/settings', { _method: 'PUT', sales_enabled: 1 });
    await save(serverUrl + '/settings', { _method: 'PUT', enabled: 1 });
    await player.goto('/modules/shop?server=' + serverId);
    await expect(player.locator('.shop-product-card')).toHaveCount(2);
    await expect(player.locator('.shop-product-artwork[data-shop-art-count="1"]')).toHaveCount(1);
    await expect(player.locator('.shop-product-artwork[data-shop-art-count="2"]')).toHaveCount(1);
    await player.locator('.shop-product-card .shop-product-details summary').last().click();
    await expect(player.locator('.shop-product-card .shop-product-details').last()).toHaveAttribute('open', '');
    const checkout = player.locator('form[action$="/shop/checkout"]').first();
    const uuid = await checkout.locator('[name=request_uuid]').inputValue();
    const productId = await checkout.locator('[name=product_id]').inputValue();
    const playerToken = await player.locator('meta[name=csrf-token]').getAttribute('content');
    await checkout.locator('button').click();
    await expect(player.locator('[role=status]')).toContainText('Покупка завершена');
    const replay = await player.request.post('/modules/shop/checkout', { form: { _token: playerToken, product_id: productId, request_uuid: uuid, price_coins: 1, quantity: 999 } });
    expect(replay.ok()).toBe(true);
    await player.goto('/modules/shop/history');
    await expect(player.locator('.reward-history-card')).toHaveCount(1);
    const receipt = await player.locator('a[href*="/shop/history/"]').first().getAttribute('href');
    await player.goto('/modules/shop');
    await player.locator('form[action$="/shop/checkout"]').nth(1).locator('button').click();
    await expect(player.locator('[role=status]')).toContainText('Покупка завершена');
    await player.goto('/modules/shop/history');
    await expect(player.locator('.reward-history-card')).toHaveCount(2);
    const guest = await context.browser().newContext();
    try { expect((await guest.request.get(new URL(receipt, player.url()).href)).url()).toContain('/login'); }
    finally { await guest.close(); }
    for (const theme of ['luxury', 'kaev-aurelia', 'kaev-classic']) {
        await page.goto('/admin/account-themes');
        const activation = page.locator(`form[action$="/account-themes/${theme}/activate"]`);
        if (await activation.count()) { await activation.locator('button').click(); await expect(activation).toHaveCount(0); }
        for (const locale of ['ru', 'en']) {
            await player.goto('/language/' + locale);
            for (const route of ['/modules/shop', '/modules/shop/history', receipt]) {
                await player.goto(route);
                await expect(player.locator('h1')).toBeVisible();
                await expect(player.locator('html')).toHaveAttribute('lang', locale);
                for (const scheme of (theme === 'kaev-classic' ? ['light', 'dark'] : ['default'])) {
                    if (scheme !== 'default') {
                        await player.evaluate(s => document.documentElement.dataset.accountScheme = s, scheme);
                    }
                    for (const width of [1440, 390]) {
                        await player.setViewportSize({ width, height: 1000 });
                        expect(await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                        if (locale === 'ru') await player.screenshot({ path: testInfo.outputPath(`shop-${theme}-${scheme}-${route.split('/').at(-1)}-${width}.png`), fullPage: true });
                    }
                }
            }
        }
    }
    for (const url of ['/admin/extensions/shop', serverUrl, '/admin/extensions/shop/purchases', receipt.replace('/modules/shop/history/', '/admin/extensions/shop/purchases/')]) {
        await page.goto(url);
        await expect(page.locator('h1')).toBeVisible();
        for (const scheme of ['light', 'dark']) {
            await page.evaluate(s => document.documentElement.dataset.adminColorScheme = s, scheme);
            for (const width of [1440, 390]) {
                await page.setViewportSize({ width, height: 1000 });
                expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `${url} ${scheme} ${width}`).toBeLessThanOrEqual(1);
                await page.screenshot({ path: testInfo.outputPath(`shop-admin-${url.split('/').at(-1)}-${scheme}-${width}.png`), fullPage: true });
            }
        }
    }
    await save('/admin/extensions/shop/settings', { _method: 'PUT', sales_enabled: 0 });
    await player.goto('/modules/shop/history');
    await expect(player.locator('.reward-history-card')).toHaveCount(2);
});
