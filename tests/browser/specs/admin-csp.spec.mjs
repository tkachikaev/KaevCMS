import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const email = process.env.PLAYWRIGHT_ADMIN_EMAIL || 'browser-admin@example.test';
const password = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'BrowserPassword123!';

const signIn = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await submitLogin({
        page, postPath: '/admin/login', label: 'Admin CSP administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await expect(page).toHaveURL(/\/admin$/);
};

const recordViolations = page => page.addInitScript(() => {
    window.cspViolations = [];
    document.addEventListener('securitypolicyviolation', event => {
        window.cspViolations.push(`${event.effectiveDirective} ${event.sourceFile}:${event.lineNumber}`);
    });
});

// Every inline <style> must carry the response nonce and actually apply.
const unappliedStyles = page => page.evaluate(() => [...document.querySelectorAll('style')]
    .filter(style => !style.nonce || ![...document.styleSheets].some(sheet => sheet.ownerNode === style))
    .map(style => style.textContent.trim().slice(0, 40)));

test('admin pages and the content editor keep their inline styles under the CSP', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await recordViolations(page);
    await signIn(page);

    for (const path of ['/admin', '/admin/news', '/admin/news/create', '/admin/pages/create', '/admin/settings', '/admin/settings/game-server']) {
        const response = await gotoWithLocalNetworkRetry(page, path);
        expect(response.headers()['content-security-policy']).toMatch(/style-src 'self' 'nonce-[A-Za-z0-9]+'/);
        expect(await page.evaluate(() => window.cspViolations), path).toEqual([]);
        expect(await unappliedStyles(page), path).toEqual([]);
    }

    // The editor styles must also apply when the editor opens after Livewire navigation.
    await gotoWithLocalNetworkRetry(page, '/admin/news');
    const documentId = await page.evaluate(() => (window.adminCspDocumentId = Math.random()));
    await page.locator('a[href$="/admin/news/create"]').first().click();
    await expect(page).toHaveURL(/\/admin\/news\/create$/);
    await expect(page.locator('[data-rich-editor]').first()).toHaveAttribute('data-editor-ready', '1');
    expect(await page.evaluate(() => window.adminCspDocumentId)).toBe(documentId);
    await expect(page.locator('style[data-tiptap-style]')).toHaveCount(1);
    expect(await unappliedStyles(page)).toEqual([]);
    expect(await page.locator('.ProseMirror').first().evaluate(editor => getComputedStyle(editor).whiteSpace)).toBe('break-spaces');
    expect(errors).toEqual([]);
});

test('YooMoney test dialog keeps waiting after a mismatching notification', async ({ page }) => {
    // The button needs a public HTTPS notification address, which a 127.0.0.1 test site has
    // not, so the shipped script runs against the dialog markup on a stand-in origin.
    const origin = 'https://pay.kaevcms.test';
    const script = resolve(import.meta.dirname, '../../../public/assets/modules/donations-admin.js');
    // The address is public: a forged body only marks the window, YooMoney's answer may follow.
    const answers = [
        { status: 'signature_mismatch', waiting: true },
        { status: 'signature_mismatch', waiting: false },
    ];
    let polls = 0;
    await page.route(`${origin}/**`, async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/donations-admin.js') return route.fulfill({ path: script, contentType: 'text/javascript' });
        if (path === '/notification-test') return route.fulfill({ json: { token: 'a'.repeat(40), expires_in: 300 } });
        if (path === `/notification-test/${'a'.repeat(40)}`) return route.fulfill({ json: answers[Math.min(polls++, answers.length - 1)] });

        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><meta name="csrf-token" content="t">
            <button type="button" data-yoomoney-test-open data-start-url="/notification-test" data-status-url="/notification-test/${'0'.repeat(40)}">Тест</button>
            <dialog data-yoomoney-test-dialog data-state="waiting">
                <div data-test-copy="waiting">Ждём</div>
                <div data-test-copy="received" hidden>Получено</div>
                <div data-test-copy="signature_mismatch" hidden>Секрет не совпадает<small data-test-still-waiting hidden>Ждём дальше</small></div>
                <div data-test-copy="expired" hidden>Не пришло</div>
                <div data-test-copy="error" hidden><p data-test-error></p></div>
                <button type="button" data-yoomoney-test-close>Закрыть</button>
                <button type="button" data-yoomoney-test-retry hidden>Повторить</button>
            </dialog>
            <script src="/donations-admin.js"></script>` });
    });
    await page.goto(`${origin}/`);

    await page.locator('[data-yoomoney-test-open]').click();
    const dialog = page.locator('[data-yoomoney-test-dialog]');
    const mismatch = dialog.locator('[data-test-copy="signature_mismatch"]');
    const stillWaiting = dialog.locator('[data-test-still-waiting]');

    await expect(mismatch).toBeVisible();
    await expect(stillWaiting).toBeVisible();
    await expect(dialog.locator('[data-yoomoney-test-retry]')).toBeVisible();

    await expect(stillWaiting).toBeHidden({ timeout: 6_000 });
    await expect(mismatch).toBeVisible();
    const finalPolls = polls;
    await page.waitForTimeout(2_500);
    expect(polls).toBe(finalPolls);
    await dialog.locator('[data-yoomoney-test-close]').click();
    await expect(dialog).not.toHaveAttribute('open');
});
