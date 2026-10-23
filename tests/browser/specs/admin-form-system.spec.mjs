import { expect, test } from '@playwright/test';
import { resolve } from 'node:path';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const email = process.env.PLAYWRIGHT_ADMIN_EMAIL || 'browser-admin@example.test';
const password = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'BrowserPassword123!';

const signIn = async (page) => {
    await gotoWithLocalNetworkRetry(page, '/admin/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await submitLogin({
        page,
        postPath: '/admin/login',
        label: 'Administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await expect(page).toHaveURL(/\/admin$/);
};

// Shapes come from the design tokens in base.css, so a design change does not need new numbers here.
const designToken = (page, name) => page.evaluate((token) => getComputedStyle(document.documentElement).getPropertyValue(token).trim(), name);

for (const appearance of ['light', 'dark']) {
    test(`editor link dialog uses shared controls and preserves safe link editing (${appearance})`, async ({ page }) => {
        await page.setViewportSize(appearance === 'dark' ? { width: 390, height: 844 } : { width: 1280, height: 900 });
        await signIn(page);
        await gotoWithLocalNetworkRetry(page, '/admin/news/create');
        await page.evaluate((scheme) => window.KaevCMSAdminAppearance.apply(scheme), appearance);

        const editor = page.locator('[data-rich-editor]').first();
        const canvas = editor.locator('.ProseMirror');
        await canvas.fill('Server guide');
        await canvas.press('Control+A');
        await editor.locator('[data-editor-link]').click();
        const dialog = editor.locator('[data-editor-link-dialog]');
        const url = dialog.locator('[data-editor-link-url]');
        const apply = dialog.locator('[data-editor-link-apply]');
        await expect(dialog).toBeVisible();
        await expect(url).toBeFocused();
        await expect(apply).toHaveClass(/button-primary/);

        const layout = await dialog.evaluate(element => {
            const input = element.querySelector('input');
            const content = element.querySelector('.rich-editor-dialog-content');
            const bounds = element.getBoundingClientRect();
            return {
                padding: parseFloat(getComputedStyle(content).paddingLeft),
                inputHeight: input.getBoundingClientRect().height,
                left: bounds.left,
                right: bounds.right,
                viewport: innerWidth,
                overflow: element.scrollWidth - element.clientWidth,
                background: getComputedStyle(element).backgroundColor,
                fieldBackground: getComputedStyle(input).backgroundColor,
                cardBackground: getComputedStyle(document.querySelector('.form-card')).backgroundColor,
                sharedFieldBackground: getComputedStyle(document.querySelector('#title_ru')).backgroundColor,
            };
        });
        expect(layout.padding).toBeGreaterThanOrEqual(20);
        expect(layout.inputHeight).toBeGreaterThanOrEqual(42);
        expect(layout.left).toBeGreaterThanOrEqual(15);
        expect(layout.right).toBeLessThanOrEqual(layout.viewport - 15);
        expect(layout.overflow).toBeLessThanOrEqual(1);
        expect(layout.background).toBe(layout.cardBackground);
        expect(layout.fieldBackground).toBe(layout.sharedFieldBackground);
        await page.screenshot({ path: resolve(import.meta.dirname, `../../../storage/framework/testing/editor-dialogs/link-${appearance}.png`) });

        await url.fill('javascript:alert(1)');
        await apply.click();
        await expect(dialog).toBeVisible();
        await expect(editor.locator('.rich-editor-source')).not.toHaveValue(/javascript:/);
        await url.fill('https://example.com/guide');
        await apply.click();
        await expect(dialog).not.toBeVisible();
        await expect(canvas.locator('a')).toHaveAttribute('href', 'https://example.com/guide');

        await canvas.locator('a').click();
        await editor.locator('[data-editor-link]').click();
        await expect(url).toHaveValue('https://example.com/guide');
        await url.fill('https://example.com/cancelled');
        await dialog.locator('[data-editor-dialog-cancel]').click();
        await expect(dialog).not.toBeVisible();
        await expect(canvas.locator('a')).toHaveAttribute('href', 'https://example.com/guide');

        const imageDialog = editor.locator('[data-editor-image-dialog]');
        await imageDialog.evaluate(element => element.showModal());
        await expect(imageDialog.locator('[data-editor-image-alt]')).toHaveCSS('min-height', '42px');
        await expect(imageDialog.locator('[data-editor-image-size]')).toHaveCSS('min-height', '42px');
        await expect(imageDialog.locator('.rich-editor-dialog-content')).toHaveCSS('padding', '24px');
        const imageOverflow = await imageDialog.evaluate(element => element.scrollWidth - element.clientWidth);
        expect(imageOverflow).toBeLessThanOrEqual(1);
        await imageDialog.locator('[data-editor-dialog-cancel]').click();
        await expect(imageDialog).not.toBeVisible();
    });
}

test('shared admin fields keep the security-page control dimensions', async ({ page }) => {
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/settings/security');

    const fields = page.locator('.security-field-grid').first().locator('.admin-field');
    const field = fields.first();
    const input = field.locator('input').first();

    await expect(field.locator('.admin-field-label')).toBeVisible();
    await expect(input).toBeVisible();

    const firstRowBoxes = await Promise.all([
        fields.nth(0).boundingBox(),
        fields.nth(1).boundingBox(),
    ]);
    expect(firstRowBoxes[0]).not.toBeNull();
    expect(firstRowBoxes[1]).not.toBeNull();
    expect(Math.abs(firstRowBoxes[0].y - firstRowBoxes[1].y)).toBeLessThanOrEqual(1);

    const styles = await input.evaluate((element) => {
        const computed = getComputedStyle(element);

        return {
            height: element.getBoundingClientRect().height,
            borderWidth: computed.borderTopWidth,
            borderRadius: computed.borderTopLeftRadius,
        };
    });

    expect(styles.height).toBeGreaterThanOrEqual(42);
    expect(styles.borderWidth).toBe('1px');
    expect(styles.borderRadius).toBe(await designToken(page, '--admin-radius-control'));
});

test('content publication uses the same modern toggle without mobile overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/pages/create');

    const toggles = page.locator('.admin-toggle-row-compact .admin-switch-control');
    await expect(toggles).toHaveCount(3);

    const firstCheckbox = toggles.first().locator('input[type="checkbox"]');
    const box = await firstCheckbox.boundingBox();
    expect(box).not.toBeNull();
    expect(box.width).toBeGreaterThanOrEqual(44);
    expect(box.height).toBeGreaterThanOrEqual(24);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});

test('shared cards tabs buttons and filters keep one visual contract', async ({ page }) => {
    await signIn(page);
    await gotoWithLocalNetworkRetry(page, '/admin/settings');

    const tabs = page.locator('.admin-tabs').first();
    const activeTab = tabs.locator('.admin-tab.active').first();
    await expect(tabs).toBeVisible();
    await expect(activeTab).toBeVisible();
    await expect(activeTab).toHaveCSS('display', 'flex');

    await gotoWithLocalNetworkRetry(page, '/admin/users');
    const filter = page.locator('.admin-filter-bar').first();
    const search = filter.locator('input[type="search"]');
    const submit = filter.getByRole('button', { name: 'Применить', exact: true });
    await expect(filter).toBeVisible();
    await expect(search).toHaveCSS('border-radius', await designToken(page, '--admin-radius-control'));
    await expect(search).toHaveCSS('border-top-style', 'solid');
    await expect(submit).toHaveClass(/button-primary/);

    await gotoWithLocalNetworkRetry(page, '/admin/extensions/support-tickets/settings');
    const card = page.locator('.admin-card').first();
    await expect(card).toBeVisible();
    await expect(card).toHaveCSS('border-radius', await designToken(page, '--admin-radius-card'));
    await expect(page.locator('label[for="allow_editor_view"] strong')).toHaveCSS('display', 'block');

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});
