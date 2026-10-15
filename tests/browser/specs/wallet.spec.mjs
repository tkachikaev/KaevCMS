import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

test('custom currency names and PNG/WebP icons persist, render in both themes and can be removed', async ({ page, context }) => {
    test.setTimeout(90_000);
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill('browser-wallet-administrator@example.test');
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({
        page, postPath: '/admin/login', label: 'Wallet appearance administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await expect(page).toHaveURL(/\/admin$/);
    const path = '/admin/settings/wallet-appearance';
    const form = page.locator(`form[action$="${path}"]`);
    const save = async () => {
        await form.getByRole('button', { name: 'Сохранить настройки', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${path}$`));
        await expect(page.locator('.field-error')).toHaveCount(0);
    };
    const activate = async (theme) => {
        await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
        const activation = page.locator(`form[action$="/account-themes/${theme}/activate"]`);
        if (await activation.count()) {
            await activation.getByRole('button').click();
            await expect(activation).toHaveCount(0);
        }
    };
    await gotoWithLocalNetworkRetry(page, path);
    // The browser runner creates a new database with no custom wallet image.
    await expect(form.locator('[data-settings-preview-image]')).toHaveCount(0);
    const originalFields = await form.locator('input[type="text"]').evaluateAll(
        (inputs) => inputs.map((input) => ({ id: input.id, value: input.value })),
    );
    const player = await context.newPage();
    await gotoWithLocalNetworkRetry(player, '/login');
    await player.locator('#login').fill(process.env.PLAYWRIGHT_PLAYER_EMAIL);
    await player.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({
        page: player, postPath: '/login', label: 'Wallet appearance player login',
        submit: () => player.locator('form').getByRole('button').click(),
    });
    await gotoWithLocalNetworkRetry(player, '/account/wallet');
    const amount = (await player.locator('.account-topbar-wallet-amount').innerText()).replace(/\s/g, '');
    const entryCount = await player.locator('.account-wallet-entry').count();
    let changed = false;
    try {
        for (const [locale, values] of Object.entries({
            ru: { one: 'дублон', few: 'дублона', many: 'дублонов' },
            en: { one: 'doubloon', few: 'doubloons', many: 'doubloons' },
        })) {
            await form.getByRole('tab', { name: locale.toUpperCase(), exact: true }).click();
            for (const [key, value] of Object.entries(values)) await form.locator(`#wallet_${locale}_${key}`).fill(value);
        }
        await form.locator('#wallet_symbol').fill('DBL');
        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
        await form.locator('#wallet_icon').setInputFiles({ name: 'doubloon.png', mimeType: 'image/png', buffer: png });
        await save();
        changed = true;
        await page.reload();
        await expect(form.locator('#wallet_symbol')).toHaveValue('DBL');
        await expect(form.locator('#wallet_ru_many')).toHaveValue('дублонов');
        await expect(form.locator('#wallet_en_many')).toHaveValue('doubloons');
        let iconUrl = await form.locator('[data-settings-preview-image]').getAttribute('src');
        expect(iconUrl).toMatch(/wallet-coin\/[a-f0-9-]+\.png$/);

        for (const format of ['png', 'webp']) {
            if (format === 'webp') {
                await gotoWithLocalNetworkRetry(page, path);
                const data = await page.evaluate(() => {
                    const canvas = document.createElement('canvas');
                    canvas.width = canvas.height = 16;
                    const drawing = canvas.getContext('2d');
                    drawing.fillStyle = '#d4a017';
                    drawing.fillRect(0, 0, 16, 16);
                    return canvas.toDataURL('image/webp').split(',')[1];
                });
                await form.locator('#wallet_icon').setInputFiles({ name: 'doubloon.webp', mimeType: 'image/webp', buffer: Buffer.from(data, 'base64') });
                await save();
                const previousUrl = iconUrl;
                iconUrl = await form.locator('[data-settings-preview-image]').getAttribute('src');
                expect(iconUrl).toMatch(/wallet-coin\/[a-f0-9-]+\.webp$/);
                expect((await page.request.get(previousUrl)).status()).toBe(404);
            }
            for (const theme of ['luxury', 'kaev-aurelia']) {
                await activate(theme);
                await gotoWithLocalNetworkRetry(player, '/ru/account/wallet');
                await expect(player.locator('.account-wallet-summary')).toContainText(`${amount} дублонов`);
                await expect(player.locator('.account-wallet-entry')).toHaveCount(entryCount);
                for (const width of [1440, 390]) {
                    await player.setViewportSize({ width, height: 900 });
                    const image = player.locator('.account-wallet-summary-icon .account-wallet-coin-image');
                    await expect(image).toHaveAttribute('src', iconUrl);
                    await expect.poll(() => image.evaluate((element) => element.complete && element.naturalWidth > 0)).toBe(true);
                    await player.locator('.account-profile-menu > summary').click();
                    await expect(player.locator('.account-wallet-menu-link')).toBeVisible();
                    await expect(player.locator('.account-wallet-menu-link')).toContainText(`${amount} дублонов`);
                    await expect(player.locator('.account-wallet-menu-link .account-wallet-coin-image')).toHaveAttribute('src', iconUrl);
                    await player.locator('.account-profile-menu > summary').click();
                    if (width === 390) {
                        await player.locator('[data-account-sidebar-toggle]').click();
                        await expect(player.locator('.account-nav').getByRole('link', { name: 'Мой кошелёк' })).toBeVisible();
                        await player.keyboard.press('Escape');
                    }
                    expect(await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                }
                await gotoWithLocalNetworkRetry(player, '/en/account/wallet');
                await expect(player.locator('.account-wallet-summary')).toContainText(`${amount} doubloons`);
            }
        }
        await gotoWithLocalNetworkRetry(page, path);
        await form.locator('[name="remove_icon"]').check();
        await save();
        expect((await page.request.get(iconUrl)).status()).toBe(404);
        for (const theme of ['luxury', 'kaev-aurelia']) {
            await activate(theme);
            await gotoWithLocalNetworkRetry(player, '/account/wallet');
            await expect(player.locator('.account-wallet-summary-icon .account-wallet-default-icon')).toBeVisible();
            await expect(player.locator('.account-wallet-coin-image')).toHaveCount(0);
        }
    } finally {
        if (changed) {
            await gotoWithLocalNetworkRetry(page, path);
            for (const field of originalFields) {
                const locale = field.id.match(/^wallet_(ru|en)_/)?.[1];
                if (locale) await form.getByRole('tab', { name: locale.toUpperCase(), exact: true }).click();
                await form.locator(`#${field.id}`).fill(field.value);
            }
            const removal = form.locator('[name="remove_icon"]');
            if (await removal.count()) await removal.check();
            await save();
        }
        await activate('luxury');
        await gotoWithLocalNetworkRetry(player, '/ru/account/wallet');
    }
});

test('wallet starts at zero, rejects overdrafts, permits frozen corrections and resumes spending after unlock', async ({ page, context, browser, baseURL }) => {
    test.setTimeout(90_000);
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({
        page, postPath: '/admin/login', label: 'Wallet administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await expect(page).toHaveURL(/\/admin$/);
    const player = await context.newPage();
    await gotoWithLocalNetworkRetry(player, '/login');
    await player.locator('#login').fill(process.env.PLAYWRIGHT_PLAYER_EMAIL);
    await player.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({
        page: player, postPath: '/login', label: 'Wallet player login',
        submit: () => player.locator('form').getByRole('button').click(),
    });
    await expect(player).toHaveURL(/\/account$/);
    await gotoWithLocalNetworkRetry(player, '/account/wallet');
    await expect(player.locator('.account-wallet-summary')).toContainText('0 монет');
    await expect(player.locator('.account-wallet-empty')).toBeVisible();
    await expect(player.locator('.account-wallet-entry')).toHaveCount(0);
    await gotoWithLocalNetworkRetry(page, `/admin/wallets?q=${encodeURIComponent(process.env.PLAYWRIGHT_PLAYER_EMAIL)}`);
    await page.getByTestId('admin-wallet-row').getByRole('link', { name: 'Подробнее' }).click();
    await expect(page).toHaveURL(/\/admin\/wallets\/\d+$/);
    const walletPath = new URL(page.url()).pathname;

    const adjustment = page.getByTestId('admin-wallet-adjustment-form');
    await adjustment.locator('[name="amount"]').fill('100');
    await adjustment.locator('[name="reason"]').fill('Browser initial credit');
    await adjustment.locator('[name="confirm_adjustment"]').check();
    await adjustment.getByRole('button').click();
    await expect(page.getByTestId('admin-wallet-entry')).toHaveCount(1);
    await player.reload();
    await expect(player.locator('.account-wallet-summary')).toContainText('100 монет');

    await adjustment.locator('[name="operation"]').selectOption('debit');
    await adjustment.locator('[name="amount"]').fill('30');
    await adjustment.locator('[name="reason"]').fill('Browser regular debit');
    await adjustment.locator('[name="confirm_adjustment"]').check();
    await adjustment.getByRole('button').click();
    await expect(page.getByTestId('admin-wallet-entry')).toHaveCount(2);
    await player.reload();
    await expect(player.locator('.account-wallet-summary')).toContainText('70 монет');
    await expect(player.locator('.account-wallet-entry')).toHaveCount(2);
    await expect(player.locator('.account-wallet-entry').first()).toContainText('−30');

    await adjustment.locator('[name="operation"]').selectOption('debit');
    await adjustment.locator('[name="amount"]').fill('100');
    await adjustment.locator('[name="reason"]').fill('Browser rejected overdraft');
    await adjustment.locator('[name="confirm_adjustment"]').check();
    await adjustment.getByRole('button').click();
    await expect(page.locator('.notice-danger[role="alert"]')).toContainText('На кошельке недостаточно монет.');
    await expect(page.getByTestId('admin-wallet-entry')).toHaveCount(2);
    await player.reload();
    await expect(player.locator('.account-wallet-summary')).toContainText('70 монет');

    const restriction = page.getByTestId('admin-wallet-status-form');
    await restriction.locator('[name="reason"]').fill('Browser payment dispute');
    await restriction.locator('[name="confirm_status_change"]').check();
    await restriction.getByRole('button').click();
    await expect(restriction.getByRole('button')).toHaveText('Разблокировать кошелёк');
    await expect(page.getByTestId('admin-wallet-status-event')).toHaveCount(1);

    await adjustment.locator('[name="operation"]').selectOption('debit');
    await adjustment.locator('[name="amount"]').fill('10');
    await adjustment.locator('[name="reason"]').fill('Browser confirmed reversal');
    await adjustment.locator('[name="confirm_adjustment"]').check();
    await adjustment.getByRole('button').click();
    await expect(page.getByTestId('admin-wallet-entry')).toHaveCount(3);
    await expect(page.getByTestId('admin-wallet-entry').first()).toContainText('Browser confirmed reversal');
    await expect(restriction.getByRole('button')).toHaveText('Разблокировать кошелёк');
    await expect(page.getByTestId('admin-wallet-status-event')).toHaveCount(1);

    // Cache the wallet page, change its balance in the other tab,
    // then return with Back. The old HTML must not restore the previous balance.
    await gotoWithLocalNetworkRetry(player, '/account/wallet');
    await expect(player.locator('.account-wallet-summary')).toContainText('60 монет');
    await player.getByRole('link', { name: 'Вернуться к обзору' }).click();
    await expect(player).toHaveURL(/\/account$/);
    await adjustment.locator('[name="operation"]').selectOption('credit');
    await adjustment.locator('[name="amount"]').fill('10');
    await adjustment.locator('[name="reason"]').fill('Browser additional credit');
    await adjustment.locator('[name="confirm_adjustment"]').check();
    await adjustment.getByRole('button').click();
    await expect(page.getByTestId('admin-wallet-entry')).toHaveCount(4);
    await player.goBack();
    await expect(player).toHaveURL(/\/account\/wallet$/);
    await expect(player.locator('.account-wallet-summary')).toContainText('70 монет');
    await expect(player.locator('.account-topbar-wallet')).toContainText('70');
    await expect(player.locator('.account-wallet-entry')).toHaveCount(4);
    await player.goForward();
    await expect(player).toHaveURL(/\/account$/);
    await expect(player.locator('.account-topbar-wallet')).toContainText('70');

    const activateTheme = async (slug) => {
        await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
        const form = page.locator(`form[action$="/account-themes/${slug}/activate"]`);
        if (await form.count()) {
            await form.getByRole('button').click();
            await expect(form).toHaveCount(0);
        }
    };
    try {
        for (const theme of ['luxury', 'kaev-aurelia']) {
            await activateTheme(theme);
            await gotoWithLocalNetworkRetry(player, '/account/wallet');
            await expect(player.locator(`link[href*="account-themes/${theme}/assets/css/app.css"]`)).toHaveCount(1);
            await expect(player.locator('.account-wallet-summary')).toContainText('70 монет');
            await expect(player.locator('.account-wallet-blocked')).toBeVisible();
            await expect(player.locator('.account-wallet-entry')).toHaveCount(4);
            await expect(player.locator('.account-wallet-entry').nth(1)).toContainText('−10');
            await expect(player.locator('body')).not.toContainText('Browser confirmed reversal');
            for (const width of [1440, 390]) {
                await player.setViewportSize({ width, height: 900 });
                const overflow = await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                expect(overflow).toBeLessThanOrEqual(1);
            }
        }
    } finally {
        await activateTheme('luxury');
    }

    // Another real role also manages wallets; rate limits remain enabled.
    const administratorContext = await browser.newContext({ baseURL });
    try {
        const administrator = await administratorContext.newPage();
        await gotoWithLocalNetworkRetry(administrator, '/admin/login');
        await administrator.locator('#email').fill('browser-wallet-administrator@example.test');
        await administrator.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
        await submitLogin({
            page: administrator, postPath: '/admin/login', label: 'Wallet non-owner administrator login',
            submit: () => administrator.getByRole('button', { name: 'Войти в панель' }).click(),
        });
        await gotoWithLocalNetworkRetry(administrator, walletPath);
        const status = administrator.getByTestId('admin-wallet-status-form');
        await status.locator('[name="reason"]').fill('Browser dispute resolved');
        await status.locator('[name="confirm_status_change"]').check();
        await status.getByRole('button').click();
        await expect(status.getByRole('button')).toHaveText('Заблокировать кошелёк');
        await expect(administrator.getByTestId('admin-wallet-status-event')).toHaveCount(2);
        await expect(administrator.getByTestId('admin-wallet-status-event').first()).toContainText('Browser dispute resolved');
        const correction = administrator.getByTestId('admin-wallet-adjustment-form');
        await correction.locator('[name="operation"]').selectOption('debit');
        await correction.locator('[name="amount"]').fill('1');
        await correction.locator('[name="reason"]').fill('Browser debit after unlock');
        await correction.locator('[name="confirm_adjustment"]').check();
        await correction.getByRole('button').click();
        await expect(administrator.getByTestId('admin-wallet-entry')).toHaveCount(5);
        await player.reload();
        await expect(player.locator('.account-wallet-summary')).toContainText('69 монет');
        await expect(player.locator('.account-wallet-blocked')).toHaveCount(0);
    } finally {
        await administratorContext.close();
    }
});
