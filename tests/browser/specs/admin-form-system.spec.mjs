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
        page,
        postPath: '/admin/login',
        label: 'Administrator login',
        submit: () => page.getByRole('button', { name: 'Войти в панель' }).click(),
    });
    await expect(page).toHaveURL(/\/admin$/);
};

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
    expect(parseFloat(styles.borderRadius)).toBeGreaterThanOrEqual(7);
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
    await expect(search).toHaveCSS('border-radius', '8px');
    await expect(submit).toHaveClass(/button-primary/);

    await gotoWithLocalNetworkRetry(page, '/admin/extensions/support-tickets/settings');
    const card = page.locator('.admin-card').first();
    await expect(card).toBeVisible();
    await expect(card).toHaveCSS('border-radius', '12px');
    await expect(page.locator('label[for="allow_editor_view"] strong')).toHaveCSS('display', 'block');

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});
