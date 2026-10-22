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

const expectNoHorizontalOverflow = async (page) => {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client + 1);
};

test('dashboard shows key figures, the system summary and servers', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signIn(page);

    const kpis = page.getByTestId('dashboard-kpis');
    await expect(kpis.getByRole('heading', { name: 'Мастер-аккаунты', exact: true })).toBeVisible();
    await expect(kpis.getByRole('heading', { name: 'Игровые аккаунты', exact: true })).toBeVisible();
    await expect(kpis.getByRole('heading', { name: 'Персонажи в игре', exact: true })).toBeVisible();

    const system = page.getByTestId('dashboard-system');
    await expect(system).toContainText('Диск');
    await expect(system).toContainText(/База данных · (SQLite|MySQL|MariaDB)/);
    await expect(system.getByRole('link', { name: 'Системная информация' })).toHaveAttribute('href', /\/admin\/settings\/system$/);

    const toolbar = page.getByTestId('dashboard-monitor-toolbar');
    await expect(toolbar.getByRole('button', { name: 'Проверить сейчас' })).toBeVisible();
    expect((await toolbar.boundingBox()).height).toBeLessThanOrEqual(60);
    await expect(page.getByTestId('dashboard-attention')).toBeVisible();
});

test('dashboard charts switch periods and lines', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signIn(page);

    const registrations = page.getByTestId('dashboard-chart-registrations');
    await expect(registrations.locator('[data-dash-chart] svg')).toBeVisible();

    const period = registrations.getByRole('combobox', { name: 'Период' });
    await expect(period).toHaveValue('30');
    await expect(period.locator('option')).toHaveText(['30 дней', '7 дней', '24 часа', '5 часов', '1 час']);
    await period.selectOption('1h');
    await expect(registrations.locator('.dash-chart-axis-labels')).toContainText('Сейчас');
    await period.selectOption('7');
    await expect(registrations.locator('.dash-chart-axis-labels')).toContainText('Сегодня');

    // Details appear near a line only, not anywhere in the chart's field. With no
    // registrations the lines lie on the bottom axis.
    const plot = registrations.locator('.dash-widget-plot');
    const tooltip = registrations.locator('.dash-chart-tooltip');
    const box = await plot.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + 5);
    await expect(tooltip).toBeHidden();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height * (134 / 140));
    await expect(tooltip).toBeVisible();
    await page.mouse.move(box.x + box.width / 2, box.y - 40);
    await expect(tooltip).toBeHidden();

    // Sweeping along the line, the details glide with it and change sides at most once
    // each way, even in a chart too narrow for them on either side.
    await page.setViewportSize({ width: 1100, height: 1000 });
    const sweepBox = await plot.boundingBox();
    const sweepY = sweepBox.y + sweepBox.height * (134 / 140);
    const guide = registrations.locator('.dash-chart-guide');
    let flips = 0;
    let previousSide = null;
    const steps = [...Array(60).keys()].map((step) => step / 59);
    for (const ratio of [...steps, ...steps.reverse()]) {
        await page.mouse.move(sweepBox.x + sweepBox.width * ratio, sweepY);
        if (await tooltip.isHidden()) continue;
        const tooltipBox = await tooltip.boundingBox();
        const guideX = (await guide.boundingBox()).x;
        const side = tooltipBox.x + tooltipBox.width / 2 >= guideX ? 'right' : 'left';
        if (previousSide !== null && side !== previousSide) flips += 1;
        previousSide = side;
    }
    expect(flips).toBeLessThanOrEqual(2);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.mouse.move(box.x + box.width / 2, box.y - 40);

    // A click pins the details; they stay after the pointer leaves until clicked again.
    const lineY = box.y + box.height * (134 / 140);
    await page.mouse.click(box.x + box.width / 2, lineY);
    await expect(tooltip).toHaveClass(/is-pinned/);
    await page.mouse.move(box.x + box.width / 2, box.y - 40);
    await expect(tooltip).toBeVisible();
    await page.mouse.click(box.x + box.width / 2, lineY);
    await expect(tooltip).not.toHaveClass(/is-pinned/);
    await page.mouse.move(box.x + box.width / 2, box.y - 40);
    await expect(tooltip).toBeHidden();
    await page.mouse.click(box.x + box.width / 2, lineY);
    await page.keyboard.press('Escape');
    await expect(tooltip).toBeHidden();

    // The choice is remembered per widget.
    await page.reload();
    await expect(page.getByTestId('dashboard-chart-registrations').getByRole('combobox', { name: 'Период' })).toHaveValue('7');

    // Lines can be hidden, but the last visible line stays.
    const lines = registrations.locator('.dash-metric');
    await expect(lines).toHaveCount(2);
    await lines.nth(1).click();
    await expect(lines.nth(1)).toHaveAttribute('aria-pressed', 'false');
    await lines.nth(0).click();
    await expect(lines.nth(0)).toHaveAttribute('aria-pressed', 'true');
});

test('dashboard remains readable without horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);

    await expect(page.getByTestId('dashboard-kpis')).toBeVisible();
    await expect(page.getByTestId('dashboard-system')).toBeVisible();
    await expectNoHorizontalOverflow(page);

    await page.setViewportSize({ width: 768, height: 900 });
    await expectNoHorizontalOverflow(page);
});
