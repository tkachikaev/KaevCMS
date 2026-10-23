import { expect, test } from '@playwright/test';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';
import { submitLogin } from '../support/authentication.mjs';

test('CSP blocks injected scripts while public and player navigation still work', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => {
        window.cspViolations = [];
        document.addEventListener('securitypolicyviolation', event => {
            window.cspViolations.push({ directive: event.effectiveDirective, blocked: event.blockedURI });
        });
    });
    const response = await gotoWithLocalNetworkRetry(page, '/');
    // Inspect the actual browser response rather than a static source contract.
    expect(response.headers()['content-security-policy']).toContain("script-src 'self'");
    await page.evaluate(() => {
        const inline = document.createElement('script');
        inline.textContent = 'window.injectedScriptExecuted = true';
        document.body.append(inline);
        const external = document.createElement('script');
        external.src = 'https://attacker.invalid/injected.js';
        document.body.append(external);
    });
    await expect.poll(() => page.evaluate(() => window.cspViolations.length)).toBeGreaterThanOrEqual(2);
    expect(await page.evaluate(() => Boolean(window.injectedScriptExecuted))).toBe(false);
    await gotoWithLocalNetworkRetry(page, '/login');
    await page.locator('#login').fill(process.env.PLAYWRIGHT_PLAYER_EMAIL);
    await page.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD);
    await submitLogin({
        page, postPath: '/login', label: 'Security headers player login',
        submit: () => page.locator('form').getByRole('button').click(),
    });
    const wallet = await gotoWithLocalNetworkRetry(page, '/account/wallet');
    expect(wallet.headers()['cache-control']).toContain('no-store');
    const documentId = await page.evaluate(() => (window.securityDocumentId = Math.random()));
    await page.locator('.account-nav a[href$="/account/characters"]').click();
    await expect(page.locator('.account-topbar h1')).toHaveText('Мои персонажи');
    expect(await page.evaluate(() => window.securityDocumentId)).toBe(documentId);
    const update = page.waitForResponse(response => response.request().method() === 'POST'
        && response.request().headers()['x-livewire'] !== undefined);
    await page.getByRole('tab', { name: 'По серверам' }).click();
    const reactive = await update;
    expect(reactive.status()).toBe(200);
    expect(reactive.headers()['cache-control']).toContain('no-store');
    await expect(page.getByRole('tab', { name: 'По серверам' })).toHaveAttribute('aria-selected', 'true');
    expect(await page.evaluate(() => window.cspViolations)).toEqual([]);
    expect(errors).toEqual([]);
});
