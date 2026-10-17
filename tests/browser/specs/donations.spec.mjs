import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

for (const width of [1440, 390]) {
    test(`donations settings save together with removable currencies at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await gotoWithLocalNetworkRetry(page, '/admin/login');
        await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
        await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
        await submitLogin({
            page, postPath: '/admin/login', label: 'Donation workspace administrator login',
            submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
        });
        await gotoWithLocalNetworkRetry(page, '/admin/modules');
        const enable = page.locator('[data-module-id="donations"] form[action$="/enable"]');
        if (await enable.count()) await enable.getByRole('button').click();
        await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations');
        const form = page.getByTestId('donations-workspace');
        const save = form.getByRole('button', { name: 'Сохранить изменения', exact: true });
        await expect(form.locator('button[type="submit"]')).toHaveCount(1);
        const [firstCode, secondCode] = width === 1440 ? ['RUB', 'EUR'] : ['USD', 'GBP'];
        let saves = 0;
        page.on('request', (request) => {
            if (request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/donations/workspace')) saves++;
        });
        const add = async (code, price) => {
            await form.locator('[data-donations-currency]').selectOption(code);
            await form.getByRole('button', { name: 'Добавить валюту', exact: true }).click();
            const row = page.getByTestId(`donations-rate-${code}`);
            await row.locator('[data-donations-price]').fill(price);
            return row;
        };
        const first = await add(firstCode, '1.25');
        const second = await add(secondCode, '3.00');
        await second.locator('input[type="checkbox"]').uncheck();
        await form.locator('#donations-show-in-account').check();
        await form.locator('[name="min_coins"]').fill('10');
        await form.locator('[name="max_coins"]').fill('500');
        await expect(form.locator('[data-donations-preview]')).toHaveCount(0);
        await expect(form.locator('[data-donations-save-status]')).toContainText('Есть несохранённые изменения');
        expect(saves).toBe(0);
        await save.click();
        await expect(page.getByText('Настройки и валюты сохранены.', { exact: true })).toBeVisible();
        await expect(form.locator('[data-donations-save-status]')).toBeHidden();
        expect(saves).toBe(1);
        await page.reload();
        await expect(first.locator('[data-donations-price]')).toHaveValue('1.25');
        await expect(first.locator('input[type="checkbox"]')).toBeChecked();
        await expect(second.locator('input[type="checkbox"]')).not.toBeChecked();
        await expect(form.locator('#donations-show-in-account')).toBeChecked();
        await expect(form.locator('[name="min_coins"]')).toHaveValue('10');
        await expect(form.locator('[name="max_coins"]')).toHaveValue('500');
        await page.screenshot({ path: `storage/framework/testing/donations-workspace-${width}.png`, fullPage: true });

        await first.getByRole('button', { name: 'Удалить валюту', exact: true }).click();
        await expect(first.locator('[data-donations-removal]')).toBeVisible();
        await expect(first.locator('[data-donations-fields]')).toBeHidden();
        await first.getByRole('button', { name: 'Отменить удаление', exact: true }).click();
        await expect(first.locator('[data-donations-price]')).toBeVisible();
        await expect(first.locator('[data-donations-price]')).toHaveValue('1.25');
        await first.getByRole('button', { name: 'Удалить валюту', exact: true }).click();
        await second.locator('input[type="checkbox"]').check();
        await second.locator('[data-donations-price]').fill('0.001');
        await save.click();
        await expect(second.locator('.admin-field-error')).toBeVisible();
        await expect(second.locator('input[type="checkbox"]')).toBeChecked();
        await expect(first.locator('[data-donations-removal]')).toBeVisible();
        await second.locator('[data-donations-price]').fill('2.50');
        await save.click();
        await expect(first).toHaveCount(0);
        await page.reload();
        await expect(first).toHaveCount(0);
        await expect(second.locator('[data-donations-price]')).toHaveValue('2.50');
        await expect(second.locator('input[type="checkbox"]')).toBeChecked();
        await expect(form.locator(`[data-donations-currency] option[value="${firstCode}"]`)).toBeEnabled();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);

        // The module script must initialize again after Livewire navigation.
        await page.getByRole('link', { name: 'Платёжные системы', exact: true }).click();
        await expect(page).toHaveURL(/\/donations\/providers$/);
        const provider = page.locator('.donations-provider-card[data-provider="yoomoney"]');
        await expect(provider.getByRole('heading', { name: 'ЮMoney', exact: true })).toBeVisible();
        await expect(provider.locator('img')).toHaveJSProperty('complete', true);
        expect(await provider.locator('img').evaluate(image => image.naturalWidth)).toBeGreaterThan(0);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
        await provider.getByRole('link', { name: 'Настроить', exact: true }).click();
        const simpleSettings = page.getByTestId('donations-yoomoney-simple-settings');
        await expect(simpleSettings).toBeVisible();
        await expect(simpleSettings.locator('#yoomoney-wallet-number')).toBeVisible();
        await expect(simpleSettings.locator('#yoomoney-secret')).toHaveAttribute('type', 'password');
        await expect(simpleSettings.locator('[name="payment_enabled"][type="checkbox"]')).not.toBeChecked();
        await expect(page.locator('#donations-payment-mode')).toHaveCount(0);
        // Merely visiting setup must never activate payments or create a transfer.
        await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations/providers/yoomoney');
        await expect(simpleSettings.locator('[name="payment_enabled"][type="checkbox"]')).not.toBeChecked();
        await page.locator('.donations-navigation').getByRole('link', { name: 'Настройки', exact: true }).click();
        await add(firstCode, '1.00');
        await page.getByTestId(`donations-rate-${firstCode}`).getByRole('button', { name: 'Удалить валюту', exact: true }).click();
        await expect(page.getByTestId(`donations-rate-${firstCode}`)).toHaveCount(0);
    });
}

test('players can preview accurate coin prices but cannot create fake payment drafts in both themes', async ({ page, context, browser }) => {
    test.setTimeout(90_000);
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({
        page, postPath: '/admin/login', label: 'Donation player flow administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await gotoWithLocalNetworkRetry(page, '/admin/modules');
    const enable = page.locator('[data-module-id="donations"] form[action$="/enable"]');
    if (await enable.count()) await enable.getByRole('button').click();
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations');
    const form = page.getByTestId('donations-workspace');
    const rate = page.getByTestId('donations-rate-RUB');
    if (!await rate.count()) {
        await form.locator('[data-donations-currency]').selectOption('RUB');
        await form.getByRole('button', { name: 'Добавить валюту', exact: true }).click();
    }
    await rate.locator('[data-donations-price]').fill('1.25');
    await rate.locator('input[type="checkbox"]').check();
    for (const [code, price] of [['USD', '0.29'], ['JPY', '2'], ['KWD', '0.125']]) {
        const currencyRate = page.getByTestId(`donations-rate-${code}`);
        if (!await currencyRate.count()) {
            await form.locator('[data-donations-currency]').selectOption(code);
            await form.getByRole('button', { name: 'Добавить валюту', exact: true }).click();
        }
        await currencyRate.locator('[data-donations-price]').fill(price);
        await currencyRate.locator('input[type="checkbox"]').check();
    }
    await form.locator('#donations-show-in-account').check();
    await form.locator('[name="min_coins"]').fill('1');
    await form.locator('[name="max_coins"]').fill('1000000');
    await form.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByText('Настройки и валюты сохранены.', { exact: true })).toBeVisible();

    const player = await context.newPage();
    const calculatorErrors = [];
    player.on('pageerror', error => calculatorErrors.push(error.message));
    await gotoWithLocalNetworkRetry(player, '/login');
    await player.locator('#login').fill('browser-donations-player@example.test');
    await player.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({
        page: player, postPath: '/login', label: 'Donation coin selection player login',
        submit: () => player.locator('form').getByRole('button').click(),
    });
    for (const theme of ['kaev-aurelia', 'luxury']) {
        await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
        const activation = page.locator(`form[action$="/account-themes/${theme}/activate"]`);
        if (await activation.count()) {
            await activation.getByRole('button').click();
            await expect(activation).toHaveCount(0);
        }
        for (const width of [1440, 390]) {
            await player.setViewportSize({ width, height: 1000 });
            await gotoWithLocalNetworkRetry(player, '/account/wallet');
            const topUp = player.getByRole('link', { name: 'Пополнить баланс', exact: true });
            await expect(topUp).toHaveAttribute('href', /\/modules\/donations$/);
            await topUp.click();
            await expect(player.locator('[data-donations-dialog]')).toBeVisible();
            await expect(player).toHaveURL(/\/account\/wallet$/);
            await expect(player.getByTestId('donations-player-calculator')).toBeVisible();
            await player.keyboard.press('Escape');
            await expect(player.locator('[data-donations-dialog]')).not.toBeVisible();
            await gotoWithLocalNetworkRetry(player, '/modules/donations/orders');
            await expect(player.locator('a[data-account-module-id="donations"]')).toHaveAttribute('href', /\/modules\/donations$/);
            await expect(player.locator('a[data-account-module-id="donations"]')).toContainText('Пополнить монеты');
            const choose = player.getByTestId('donations-choose-coins');
            await expect(choose).toBeInViewport();
            await choose.click();
            await expect(player.locator('[data-donations-dialog]')).toBeVisible();
            await expect(player).toHaveURL(/\/modules\/donations\/orders$/);
            await player.keyboard.press('Escape');
            await gotoWithLocalNetworkRetry(player, '/modules/donations');
            const quote = player.getByTestId('donations-quote-form');
            const coins = quote.locator('[name="coins"]');
            const slider = quote.locator('[type="range"]');
            const result = player.getByTestId('donations-quote-result');
            const draft = player.getByTestId('donations-create-order');
            await expect(draft).toHaveCount(0);
            const originalUrl = player.url();
            let quoteRequests = 0;
            const countQuoteRequests = request => {
                if (request.method() === 'GET' && new URL(request.url()).pathname === '/modules/donations') quoteRequests++;
            };
            player.on('request', countQuoteRequests);
            await expect(coins).toBeInViewport();
            await expect(slider).toBeVisible();
            expect(await player.getByTestId('donations-player-calculator').evaluate(element => parseFloat(getComputedStyle(element).borderTopLeftRadius))).toBeGreaterThanOrEqual(16);
            await quote.locator('[name="currency"]').selectOption('RUB');
            await slider.focus();
            await slider.press('Home');
            await slider.press('ArrowRight');
            await expect(coins).toHaveValue('2');
            await expect(result).toContainText('2.50 RUB');
            await slider.press('End');
            await expect(coins).toHaveValue('1000000');
            await expect(result).toContainText('1250000.00 RUB');
            for (const invalid of ['', '0', '1000001', '1.5']) {
                await coins.fill(invalid);
                await expect(player.getByTestId('donations-quote-error')).toBeVisible();
                await expect(result.locator('[data-donations-total]')).toHaveText('—');
                await expect(draft).toHaveCount(0);
            }
            await coins.fill('237');
            await expect(slider).toHaveValue('237');
            for (const [code, amount] of [['USD', '68.73'], ['JPY', '474'], ['KWD', '29.625'], ['RUB', '296.25']]) {
                await quote.locator('[name="currency"]').selectOption(code);
                await expect(result).toContainText(`${amount} ${code}`);
            }
            await coins.press('Enter');
            await expect(player).toHaveURL(originalUrl);
            await expect(player.getByTestId('donations-quote-error')).toBeHidden();
            await expect(draft).toHaveCount(0);
            await expect(player.getByText('Пополнение сейчас недоступно. Попробуйте позже.')).toBeVisible();
            expect(quoteRequests).toBe(0);
            player.off('request', countQuoteRequests);
            expect(await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
            await player.screenshot({ path: `storage/framework/testing/donations-player-${theme}-${width}.png`, fullPage: true });
            await expect(draft).toHaveCount(0);
            await coins.fill('10');
            await quote.locator('[name="currency"]').selectOption('USD');
            await expect(result).toContainText('2.90 USD');
            await expect(slider).toHaveValue('10');
        }
    }
    const fallbackContext = await browser.newContext({ storageState: await context.storageState(), javaScriptEnabled: false });
    const fallback = await fallbackContext.newPage();
    await gotoWithLocalNetworkRetry(fallback, '/modules/donations');
    await expect(fallback.locator('[type="range"]')).toBeHidden();
    await fallback.locator('#donations-player-coins').fill('13');
    await fallback.locator('#donations-player-currency').selectOption('RUB');
    await fallback.getByRole('button', { name: 'Рассчитать', exact: true }).click();
    await expect(fallback.getByTestId('donations-quote-result')).toContainText('16.25 RUB');
    await fallbackContext.close();
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations');
    await form.locator('[name="min_coins"]').fill('10');
    await form.locator('[name="max_coins"]').fill('500');
    await form.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByText('Настройки и валюты сохранены.', { exact: true })).toBeVisible();
    await gotoWithLocalNetworkRetry(player, '/modules/donations?coins=5&currency=RUB');
    await expect(player.getByTestId('donations-quote-error')).toBeVisible();
    const limitedSlider = player.locator('#donations-player-range');
    await expect(limitedSlider).toHaveAttribute('min', '10');
    await expect(limitedSlider).toHaveAttribute('max', '500');
    await limitedSlider.focus();
    await limitedSlider.press('End');
    await expect(player.locator('#donations-player-coins')).toHaveValue('500');
    await expect(player.getByTestId('donations-quote-result')).toContainText('625.00 RUB');
    await expect(player.getByTestId('donations-create-order')).toHaveCount(0);
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations');
    await form.locator('#donations-show-in-account').uncheck();
    await form.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByText('Настройки и валюты сохранены.', { exact: true })).toBeVisible();
    await gotoWithLocalNetworkRetry(player, '/modules/donations/orders');
    await expect(player.locator('a[data-account-module-id="donations"]')).toHaveAttribute('href', /\/modules\/donations\/orders$/);
    await expect(player.getByTestId('donations-choose-coins')).toHaveCount(0);
    // Previewing prices must not create payment orders.
    await expect(player.getByTestId('donations-player-orders').locator('a')).toHaveCount(0);
    await expect(player.getByTestId('donations-player-orders')).toContainText('Заявок пока нет.');
    await gotoWithLocalNetworkRetry(player, '/account/wallet');
    await expect(player.getByRole('link', { name: 'Пополнить баланс', exact: true })).toHaveCount(0);

    // Exercise the optional order form in this disposable testing database.
    // No order is submitted; block the external provider as an extra guard.
    await context.route('https://yoomoney.ru/**', route => route.abort());
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations/providers/yoomoney');
    const providerSettings = page.getByTestId('donations-yoomoney-simple-settings');
    await providerSettings.locator('#yoomoney-wallet-number').fill('410012345678901');
    await providerSettings.locator('#yoomoney-secret').fill('browser-calculator-fixture-secret');
    const accepting = providerSettings.locator('[name="payment_enabled"][type="checkbox"]');
    await accepting.check();
    await providerSettings.locator('button[type="submit"]').click();
    await expect(page.getByText('Настройки платёжной системы сохранены.', { exact: true })).toBeVisible();
    await gotoWithLocalNetworkRetry(player, '/modules/donations');
    const order = player.getByTestId('donations-create-order');
    const activeCoins = player.locator('#donations-player-coins');
    await expect(order).toBeVisible();
    await expect(player.locator('#donations-player-range')).toBeVisible();
    await activeCoins.fill('237');
    await expect(order.locator('[name="coins"]')).toHaveValue('237');
    await expect(order.locator('[name="currency"]')).toHaveValue('RUB');
    await expect(player.getByTestId('donations-quote-result')).toContainText('296.25 RUB');
    await activeCoins.fill('0');
    await expect(order.locator('[type="submit"]')).toBeDisabled();
    await expect(order.locator('[name="coins"]')).toHaveValue('');
    await activeCoins.fill('239');
    await expect(order.locator('[type="submit"]')).toBeEnabled();
    await expect(order.locator('[name="coins"]')).toHaveValue('239');
    await expect(player.locator('#donations-player-range')).toHaveValue('239');
    await accepting.uncheck();
    await providerSettings.locator('button[type="submit"]').click();
    await expect(page.getByText('Настройки платёжной системы сохранены.', { exact: true })).toBeVisible();
    await player.reload();
    await expect(order).toHaveCount(0);
    await expect(player.locator('#donations-player-range')).toBeVisible();
    expect(calculatorErrors).toEqual([]);
    await player.close();
});

test('donations calculator stays translated and gated in RU/EN, both account themes and mobile', async ({ page, context }) => {
    test.setTimeout(90_000);
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({ page, postPath: '/admin/login', label: 'Donation translation administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click() });
    await gotoWithLocalNetworkRetry(page, '/admin/modules');
    const enable = page.locator('[data-module-id="donations"] form[action$="/enable"]');
    if (await enable.count()) await enable.getByRole('button').click();
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations');
    const form = page.getByTestId('donations-workspace');
    const initial = { visible: await form.locator('#donations-show-in-account').isChecked(),
        min: await form.locator('[name="min_coins"]').inputValue(), max: await form.locator('[name="max_coins"]').inputValue() };
    if (!await page.getByTestId('donations-rate-RUB').count()) {
        await form.locator('[data-donations-currency]').selectOption('RUB');
        await form.getByRole('button', { name: 'Добавить валюту', exact: true }).click();
    }
    await page.getByTestId('donations-rate-RUB').locator('[data-donations-price]').fill('1.25');
    await page.getByTestId('donations-rate-RUB').locator('input[type="checkbox"]').check();
    await form.locator('#donations-show-in-account').check();
    await form.locator('[name="min_coins"]').fill('1');
    await form.locator('[name="max_coins"]').fill('1000000');
    await form.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByText('Настройки и валюты сохранены.', { exact: true })).toBeVisible();
    const player = await context.newPage();
    await gotoWithLocalNetworkRetry(player, '/login');
    await player.locator('#login').fill(process.env.PLAYWRIGHT_PLAYER_EMAIL);
    await player.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({ page: player, postPath: '/login', label: 'Donation translation player login',
        submit: () => player.locator('form').getByRole('button').click() });
    for (const theme of ['kaev-aurelia', 'luxury']) {
        await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
        const activate = page.locator(`form[action$="/account-themes/${theme}/activate"]`);
        if (await activate.count()) {
            await activate.getByRole('button').click();
            await expect(activate).toHaveCount(0);
        }
        for (const width of [1440, 390]) {
            await player.setViewportSize({ width, height: 900 });
            for (const [locale, title] of [['ru', 'Пополнение монет'], ['en', 'Add coins']]) {
                await gotoWithLocalNetworkRetry(player, `/language/${locale}?return=%2Fmodules%2Fdonations`);
                await expect(player.locator('h1')).toHaveText(title);
                await expect(player.locator('html')).toHaveAttribute('lang', locale);
                const quote = player.getByTestId('donations-quote-form');
                await quote.locator('[name="currency"]').selectOption('RUB');
                await quote.locator('[name="coins"]').fill('237');
                await expect(player.getByTestId('donations-quote-result')).toContainText('296.25 RUB');
                await expect(player.locator('form[action*="yoomoney.ru"]')).toHaveCount(0);
                await expect(player.getByTestId('donations-start-checkout')).toHaveCount(0);
                expect(await player.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
                await player.screenshot({ path: `storage/framework/testing/donations-${locale}-${theme}-${width}.png`, fullPage: true });
            }
        }
    }
    await gotoWithLocalNetworkRetry(player, '/language/ru?return=%2Fmodules%2Fdonations');
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations');
    await form.locator('#donations-show-in-account').setChecked(initial.visible);
    await form.locator('[name="min_coins"]').fill(initial.min);
    await form.locator('[name="max_coins"]').fill(initial.max);
    await form.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
    await expect(page.getByText('Настройки и валюты сохранены.', { exact: true })).toBeVisible();
    await player.close();
});


test('wallet top-up dialog keeps context, reviews changed prices and posts once to a mocked provider', async ({ page, context }) => {
    test.setTimeout(120_000);
    await context.route('https://yoomoney.ru/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Offline provider fixture</h1>' }));
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD);
    await submitLogin({ page, postPath: '/admin/login', label: 'Top-up dialog administrator', submit: () => page.getByRole('button', { name: 'Войти в панель' }).click() });
    await gotoWithLocalNetworkRetry(page, '/admin/modules');
    const enable = page.locator('[data-module-id="donations"] form[action$="/enable"]');
    if (await enable.count()) await enable.getByRole('button').click();
    const savePrice = async price => {
        await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations');
        const workspace = page.getByTestId('donations-workspace');
        if (!await page.getByTestId('donations-rate-RUB').count()) {
            await workspace.locator('[data-donations-currency]').selectOption('RUB');
            await workspace.getByRole('button', { name: 'Добавить валюту', exact: true }).click();
        }
        await page.getByTestId('donations-rate-RUB').locator('[data-donations-price]').fill(price);
        await page.getByTestId('donations-rate-RUB').locator('input[type="checkbox"]').check();
        await workspace.locator('#donations-show-in-account').check();
        await workspace.locator('[name="min_coins"]').fill('1');
        await workspace.locator('[name="max_coins"]').fill('1000000');
        await workspace.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
        await expect(page.getByText('Настройки и валюты сохранены.', { exact: true })).toBeVisible();
    };
    await savePrice('1.25');
    await gotoWithLocalNetworkRetry(page, '/admin/extensions/donations/providers/yoomoney');
    const settings = page.getByTestId('donations-yoomoney-simple-settings');
    await settings.locator('#yoomoney-wallet-number').fill('410012345678901');
    await settings.locator('#yoomoney-secret').fill('offline-dialog-fixture-secret');
    await settings.locator('[name="payment_enabled"][type="checkbox"]').check();
    await settings.locator('button[type="submit"]').click();
    await expect(page.getByText('Настройки платёжной системы сохранены.', { exact: true })).toBeVisible();
    const player = await context.newPage();
    const errors = [];
    player.on('pageerror', error => errors.push(error.message));
    let topups = 0;
    player.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/modules/donations/top-up') topups++; });
    await gotoWithLocalNetworkRetry(player, '/login');
    await player.locator('#login').fill('browser-donations-player@example.test');
    await player.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({ page: player, postPath: '/login', label: 'Top-up dialog player', submit: () => player.locator('form').getByRole('button').click() });
    let expectedPrice = 1.25;
    for (const theme of ['kaev-aurelia', 'luxury']) {
        await gotoWithLocalNetworkRetry(page, '/admin/account-themes');
        const activate = page.locator(`form[action$="/account-themes/${theme}/activate"]`);
        if (await activate.count()) { await activate.getByRole('button').click(); await expect(activate).toHaveCount(0); }
        for (const width of [1440, 390]) {
            await player.setViewportSize({ width, height: 900 });
            await gotoWithLocalNetworkRetry(player, '/account/wallet');
            const trigger = player.getByRole('link', { name: 'Пополнить баланс', exact: true });
            const dialog = player.locator('[data-donations-dialog]');
            let before = topups;
            await trigger.click();
            await expect(dialog).toBeVisible();
            await expect(dialog.locator('#donations-player-coins')).toBeFocused();
            await expect(player).toHaveURL(/\/account\/wallet$/);
            if (width === 1440) {
                const rangeBounds = await dialog.locator('#donations-player-range').boundingBox();
                const bounds = await dialog.boundingBox();
                await player.mouse.move(rangeBounds.x + 12, rangeBounds.y + rangeBounds.height / 2);
                await player.mouse.down();
                await player.mouse.move(bounds.x + bounds.width + 20, rangeBounds.y + rangeBounds.height / 2);
                await player.mouse.up();
                await expect(dialog).toBeVisible();
                await player.mouse.click(bounds.x + bounds.width + 20, bounds.y + 20);
                await expect(dialog).not.toBeVisible();
                await trigger.press('Enter');
                await expect(dialog).toBeVisible();
            }
            await player.keyboard.press('Escape');
            await expect(dialog).not.toBeVisible();
            await expect(trigger).toBeFocused();
            expect(topups).toBe(before);
            await trigger.press('Enter');
            await expect(dialog).toBeVisible();
            await expect(dialog.locator('[name="provider"]')).toBeChecked();
            await expect(dialog.locator('.donations-provider-logo img')).toBeVisible();
            const coins = dialog.locator('#donations-player-coins');
            const pay = dialog.getByTestId('donations-create-order').locator('button[type="submit"]');
            await coins.fill('0');
            await expect(pay).toBeDisabled();
            await coins.fill('100');
            await expect(dialog.getByTestId('donations-quote-result')).toContainText(`${(100 * expectedPrice).toFixed(2)} RUB`);
            if (theme === 'kaev-aurelia' && width === 1440) {
                // Closing while preparation is in progress must cancel the browser redirect.
                let releasePreparation;
                let preparationAborted;
                const held = new Promise(resolve => { releasePreparation = resolve; });
                const aborted = new Promise(resolve => { preparationAborted = resolve; });
                await player.route('**/modules/donations/top-up', async route => {
                    await held;
                    await route.abort();
                    preparationAborted();
                }, { times: 1 });
                const started = player.waitForRequest(request => request.url().endsWith('/modules/donations/top-up'));
                await pay.click(); await started;
                await expect(pay).toBeDisabled();
                await dialog.locator('[data-donations-close]').click();
                releasePreparation(); await aborted;
                await expect(dialog).not.toBeVisible();
                await expect(player).toHaveURL(/\/account\/wallet$/);
                before = topups;
                await trigger.click();
                await expect(pay).toBeEnabled();
                await savePrice('2.00');
                const changed = player.waitForResponse(response => response.url().endsWith('/modules/donations/top-up') && response.status() === 409);
                await pay.click(); await changed;
                await expect(dialog.locator('[data-donations-submit-error]')).toContainText('Цена изменилась');
                await expect(dialog.getByTestId('donations-quote-result')).toContainText('200.00 RUB');
                expectedPrice = 2;
                expect(topups).toBe(before + 1);
            }
            await coins.fill('237');
            await expect(dialog.locator('#donations-player-range')).toHaveValue('237');
            await expect(dialog.getByTestId('donations-quote-result')).toContainText(`${(237 * expectedPrice).toFixed(2)} RUB`);
            expect(await dialog.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
            await player.screenshot({ path: `storage/framework/testing/donations-dialog-${theme}-${width}.png`, fullPage: true });
            const prepared = player.waitForResponse(response => response.url().endsWith('/modules/donations/top-up') && response.ok());
            const transfer = player.waitForRequest(request => request.url() === 'https://yoomoney.ru/quickpay/confirm');
            await pay.click();
            await prepared;
            const request = await transfer;
            await expect(player.getByRole('heading', { name: 'Offline provider fixture' })).toBeVisible();
            expect(request.method()).toBe('POST');
            const fields = new URLSearchParams(request.postData());
            expect(fields.get('sum')).toBe((237 * expectedPrice).toFixed(2));
            expect(fields.get('receiver')).toBe('410012345678901');
            expect(fields.get('paymentType')).toBe('PC');
            expect(fields.get('successURL')).toMatch(/\/modules\/donations\/orders\/[0-9a-f-]{36}$/);
            await gotoWithLocalNetworkRetry(player, fields.get('successURL'));
            await expect(player.getByTestId('donations-order-detail')).toContainText((237 * expectedPrice).toFixed(2));
            await expect(player.locator('.account-topbar-wallet-amount')).toHaveText('0');
            await player.getByRole('button', { name: 'Отменить заявку', exact: true }).click();
            await expect(player.getByTestId('donations-order-detail')).toContainText('Отменена');
            await expect(player.locator('.account-topbar-wallet-amount')).toHaveText('0');
        }
    }
    expect(errors).toEqual([]);
    await player.close();
});
