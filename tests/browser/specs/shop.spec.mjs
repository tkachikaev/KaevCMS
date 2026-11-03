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
    await page.goto(serverUrl);
    // Sections are created in place; the page is updated without a reload.
    await page.evaluate(() => { window.__shopNoReload = true; });
    await page.locator('#shop-new-section').fill('Снаряжение');
    await page.locator('form.shop-section-create button[type=submit]').click();
    const sectionLink = page.locator('a.shop-section-link[href*="section="]').first();
    await expect(sectionLink).toBeVisible();
    await page.locator('#shop-new-section').fill('Расходники');
    await page.locator('form.shop-section-create button[type=submit]').click();
    await expect(page.locator('a.shop-section-link')).toHaveCount(2);
    expect(await page.evaluate(() => window.__shopNoReload)).toBe(true);
    const sectionId = new URL(await sectionLink.getAttribute('href'), page.url()).searchParams.get('section');
    // Switching sections keeps the scroll position (measured without a flash notice above).
    await page.goto(serverUrl);
    await page.setViewportSize({ width: 1280, height: 520 });
    await page.evaluate(() => window.scrollTo(0, 120));
    const scrollBefore = await page.evaluate(() => window.scrollY);
    const otherLink = page.locator('a.shop-section-link').nth(1);
    const otherId = new URL(await otherLink.getAttribute('href'), page.url()).searchParams.get('section');
    await otherLink.click();
    await expect(page).toHaveURL(new RegExp(`section=${otherId}`));
    await expect(page.locator('.shop-section-item.is-current a.shop-section-link')).toHaveAttribute('href', new RegExp(`section=${otherId}`));
    expect(await page.evaluate(() => window.scrollY)).toBe(scrollBefore);
    await sectionLink.click();
    await expect(page).toHaveURL(new RegExp(`section=${sectionId}`));
    await page.setViewportSize({ width: 1280, height: 1000 });

    await page.locator('[data-shop-open="shop-product-new"]').click();
    const itemDialog = page.locator('#shop-product-new');
    await expect(itemDialog).toBeVisible();
    // The item name loads from the catalog and names the product automatically.
    await itemDialog.locator('[name="items[0][item_id]"]').fill('57');
    await expect(itemDialog.locator('[data-shop-item-name]').first()).toHaveText('Адена');
    await expect(itemDialog.locator('[data-shop-name="ru"]')).toHaveValue('Адена');
    await itemDialog.locator('[name="items[0][quantity]"]').fill('1000');
    await itemDialog.locator('[name="translations[ru][description]"]').fill('Монеты для начала путешествия');
    await itemDialog.locator('[name=price_coins]').fill('25');
    await itemDialog.locator('.shop-drawer-foot button[type=submit].button-primary').click();
    await expect(page.locator('.shop-admin-card')).toHaveCount(1);

    await page.locator('[data-shop-open="shop-product-new"]').click();
    const bundleDialog = page.locator('#shop-product-new');
    await bundleDialog.locator('[name="items[0][item_id]"]').fill('57');
    await bundleDialog.locator('[name="items[0][quantity]"]').fill('200');
    await bundleDialog.locator('[data-shop-item-search-input]').fill('4037');
    await bundleDialog.locator('[data-shop-item-option="4037"]').click();
    await bundleDialog.locator('[name="items[1][quantity]"]').fill('3');
    await bundleDialog.locator('[data-shop-name="ru"]').fill('Комплект путешественника');
    await bundleDialog.locator('[data-shop-locale-tab="en"]').click();
    await bundleDialog.locator('[data-shop-name="en"]').fill('Traveller bundle');
    await bundleDialog.locator('[name=price_coins]').fill('25');
    await bundleDialog.locator('.shop-drawer-foot button[type=submit].button-primary').click();
    await expect(page.locator('.shop-admin-card')).toHaveCount(2);
    await expect(page.locator('.shop-admin-card [data-shop-art-count="2"]')).toHaveCount(1);
    await page.locator('[data-shop-filter]').fill('комплект');
    await expect(page.locator('.shop-admin-card:visible')).toHaveCount(1);
    await page.locator('[data-shop-filter]').fill('');
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
    await expect(player.locator('a[href$="/modules/shop"]').first()).toBeVisible();
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
    // The full bundle opens in a dialog; the card grid keeps its height.
    const cardHeight = await player.locator('.shop-product-card').last().evaluate((card) => card.getBoundingClientRect().height);
    await player.locator('.shop-contents-button').first().click();
    const contents = player.locator('dialog.shop-contents-dialog[open]');
    await expect(contents.locator('.account-operation-reward')).toHaveCount(2);
    await contents.locator('.account-operation-modal-close').click();
    await expect(contents).toHaveCount(0);
    expect(await player.locator('.shop-product-card').last().evaluate((card) => card.getBoundingClientRect().height)).toBe(cardHeight);
    // Buying asks for confirmation in a dialog that holds the checkout form.
    await player.locator('[data-account-social-open^="shop-buy-"]').first().click();
    const checkout = player.locator('dialog[open] form[action$="/shop/checkout"]');
    await expect(checkout).toBeVisible();
    await expect(checkout.locator('.shop-buy-summary')).toContainText('75');
    const uuid = await checkout.locator('[name=request_uuid]').inputValue();
    const productId = await checkout.locator('[name=product_id]').inputValue();
    const playerToken = await player.locator('meta[name=csrf-token]').getAttribute('content');
    await checkout.locator('button[type=submit]').click();
    await expect(player.locator('[role=status]')).toContainText('Покупка завершена');
    await expect(player.locator('[data-account-operation-modal]')).toContainText('Адена');
    await player.locator('[data-account-operation-modal] [data-account-operation-modal-close]').first().click();
    const replay = await player.request.post('/modules/shop/checkout', { form: { _token: playerToken, product_id: productId, request_uuid: uuid, price_coins: 1, quantity: 999 } });
    expect(replay.ok()).toBe(true);
    await player.goto('/modules/shop/history');
    await expect(player.locator('.reward-history-card')).toHaveCount(1);
    const receipt = await player.locator('a[href*="/shop/history/"]').first().getAttribute('href');
    await player.goto('/modules/shop');
    await player.locator('[data-account-social-open^="shop-buy-"]').nth(1).click();
    await player.locator('dialog[open] form[action$="/shop/checkout"] button[type=submit]').click();
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
