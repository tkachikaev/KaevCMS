import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const email = process.env.PLAYWRIGHT_PLAYER_EMAIL || 'browser-player@example.test';
const password = process.env.PLAYWRIGHT_PLAYER_PASSWORD || 'BrowserPlayerPassword123!';

test('daily reward filters submit on change under the CSP after Livewire navigation', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => {
        window.cspViolations = [];
        document.addEventListener('securitypolicyviolation', event => {
            window.cspViolations.push(event.effectiveDirective);
        });
    });

    await gotoWithLocalNetworkRetry(page, '/login');
    await page.locator('#login').fill(email);
    await page.locator('#password').fill(password);
    await submitLogin({
        page, postPath: '/login', label: 'Daily reward filters player login',
        submit: () => page.locator('form').getByRole('button').click(),
    });
    await expect(page).toHaveURL(/\/account$/);

    await page.locator('[data-account-module-id="daily-rewards"]').click();
    await expect(page).toHaveURL(/\/modules\/daily-rewards$/);
    const account = page.locator('select[name="account"]');
    await expect(account).toBeEnabled();
    expect(await account.getAttribute('onchange')).toBeNull();

    // The seeded player owns one game account; a second option with the same value lets
    // the player's real select change without altering shared browser fixtures.
    const accountId = await account.inputValue();
    const documentId = await page.evaluate(id => {
        const select = document.querySelector('select[name="account"]');
        select.prepend(new Option('Browser filter check', id));
        select.selectedIndex = 1;
        return (window.dailyFilterDocumentId = Math.random());
    }, accountId);

    await account.selectOption({ label: 'Browser filter check' });
    await expect(page).toHaveURL(new RegExp(`/modules/daily-rewards\\?calendar=\\d+&account=${accountId}$`));
    await expect(page.locator('select[name="account"]')).toHaveValue(accountId);
    expect(await page.evaluate(() => window.dailyFilterDocumentId)).not.toBe(documentId);
    expect(await page.evaluate(() => window.cspViolations)).toEqual([]);
    expect(errors).toEqual([]);
});
