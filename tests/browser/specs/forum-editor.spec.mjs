import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';

const forumEditor = resolve(import.meta.dirname, '../../../public/assets/modules/forum/editor.js');
const sharedEditor = resolve(import.meta.dirname, '../../../public/assets/admin/js/news-editor.js');

const composeForm = `
    <form id="forum-compose">
        <div class="forum-editor" data-rich-editor data-required="1">
            <div class="rich-editor-toolbar"><button type="button" data-editor-action="bold">Bold</button></div>
            <div class="rich-editor-canvas"></div>
            <textarea class="rich-editor-source" name="body_html" disabled hidden><p>Old <strong>rich</strong> text</p></textarea>
            <label class="forum-editor-fallback">
                <textarea name="body">Old rich text</textarea>
            </label>
        </div>
    </form>
`;

test('forum sends edited plain text when the rich editor is unavailable', async ({ page }) => {
    await page.setContent(composeForm);
    await page.locator('textarea[name="body"]').fill('Corrected plain message');

    const data = await page.locator('form').evaluate((form) => Object.fromEntries(new FormData(form)));

    expect(data).toEqual({ body: 'Corrected plain message' });
});

test('forum sends rich HTML only after Tiptap is initialized', async ({ page }) => {
    await page.setContent(composeForm);
    await page.addScriptTag({ path: forumEditor });
    await page.addScriptTag({ path: sharedEditor });

    const root = page.locator('.forum-editor');
    await expect(root).toHaveAttribute('data-editor-ready', '1');
    await expect(page.locator('textarea[name="body_html"]')).toBeEnabled();
    await expect(page.locator('textarea[name="body"]')).toBeDisabled();

    const data = await page.locator('form').evaluate((form) => Object.fromEntries(new FormData(form)));

    expect(data).toEqual({ body_html: '<p>Old <strong>rich</strong> text</p>' });
});
