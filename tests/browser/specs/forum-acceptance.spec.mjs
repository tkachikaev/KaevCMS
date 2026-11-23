import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

const schemeScript = resolve(import.meta.dirname, '../../../public/assets/modules/forum/scheme.js');

test('forum follows the Aurelia appearance choice', async ({ page }) => {
    const url = 'http://forum.test/';
    await page.route(url, route => route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><html data-forum-theme="kaev-aurelia" data-forum-scheme="light"><head></head><body></body></html>',
    }));
    await page.goto(url);

    await page.evaluate(() => localStorage.setItem('kaevcms.site.scheme', 'dark'));
    await page.addScriptTag({ path: schemeScript });
    await expect(page.locator('html')).toHaveAttribute('data-forum-scheme', 'dark');

    await page.evaluate(() => localStorage.setItem('kaevcms.site.scheme', 'light'));
    await page.addScriptTag({ path: schemeScript });
    await expect(page.locator('html')).toHaveAttribute('data-forum-scheme', 'light');

    await page.locator('html').evaluate(root => {
        root.dataset.forumTheme = 'default';
        root.dataset.forumScheme = 'dark';
    });
    await page.addScriptTag({ path: schemeScript });
    await expect(page.locator('html')).toHaveAttribute('data-forum-scheme', 'light');
});

test('forum fits a small phone and offers keyboard navigation', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await gotoWithLocalNetworkRetry(page, '/forum');
    await expect(page.locator('#forum-main')).toBeVisible();
    await expect(page.locator('.forum-category')).toHaveCount(3);
    await expect(page.locator('[data-forum-activity]')).toBeVisible();
    await page.keyboard.press('Tab');
    await expect(page.locator('.forum-skip-link')).toBeFocused();

    const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    expect(await overflow()).toBeLessThanOrEqual(1);

    const toggle = page.locator('[data-forum-scheme-toggle]');
    const scheme = await page.locator('html').getAttribute('data-forum-scheme');
    await toggle.click();
    await expect(page.locator('html')).toHaveAttribute('data-forum-scheme', scheme === 'dark' ? 'light' : 'dark');

    const search = page.locator('.forum-quick-search input[name="q"]');
    await expect(search).toBeVisible();
    await search.fill('Общее');
    await search.press('Enter');
    await expect(page).toHaveURL(/\/forum\/search\?q=/);
    await expect(page.locator('.forum-quick-search input[name="q"]')).toHaveValue('Общее');
    expect(await overflow()).toBeLessThanOrEqual(1);
});

test('a player composes, edits and confirms deletion in the browser', async ({ page }) => {
    await gotoWithLocalNetworkRetry(page, '/login');
    await page.locator('#login').fill(process.env.PLAYWRIGHT_PLAYER_EMAIL || 'browser-player@example.test');
    await page.locator('#password').fill(process.env.PLAYWRIGHT_PLAYER_PASSWORD || 'BrowserPlayerPassword123!');
    await submitLogin({
        page,
        postPath: '/login',
        label: 'Forum player sign-in',
        submit: () => page.locator('form').getByRole('button').click(),
    });

    await gotoWithLocalNetworkRetry(page, '/forum');
    await page.locator('a.forum-section-title').filter({ hasText: 'Общее общение' }).click();
    await page.getByRole('link', { name: /Новая тема/ }).click();
    await page.locator('input[name="title"]').fill('Forum browser acceptance');
    await expect(page.locator('.forum-editor')).toHaveAttribute('data-editor-ready', '1');
    await page.locator('.forum-editor .tiptap').fill('A real forum message from the browser');
    await page.getByRole('button', { name: 'Опубликовать тему' }).click();
    await expect(page).toHaveURL(/\/forum\/topics\/\d+/);
    await expect(page.locator('.forum-post-body')).toContainText('A real forum message from the browser');

    await page.getByRole('link', { name: 'Изменить', exact: true }).first().click();
    await expect(page.locator('.forum-editor')).toHaveAttribute('data-editor-ready', '1');
    await page.locator('.forum-editor .tiptap').fill('Edited in a real browser');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.locator('.forum-post-body')).toContainText('Edited in a real browser');
    await expect(page.locator('.forum-post-edited')).toBeVisible();

    const confirmation = page.locator('.forum-post-tools .forum-inline-confirm').first();
    await confirmation.locator('summary').click();
    await expect(confirmation).toContainText('Вместо текста останется заглушка');
    await confirmation.getByRole('button', { name: 'Подтвердить удаление' }).click();
    await expect(page.locator('.forum-post-removed')).toContainText('Сообщение удалено автором');
    await expect(page.locator('.forum-post-body')).not.toContainText('Edited in a real browser');
});
