import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';

const editorBundle = resolve(import.meta.dirname, '../../../public/assets/admin/js/news-editor.js');

test('compiled content editor keeps block formatting scoped and emits safe semantic HTML', async ({ page }) => {
    await page.setContent(`
        <form>
            <div
                data-rich-editor
                data-required="1"
                data-editor-label="News text"
                data-empty-message="Text is required"
            >
                <div class="rich-editor-toolbar">
                    <button type="button" data-editor-action="bold">Bold</button>
                    <button type="button" data-editor-align="center">Center</button>
                    <button type="button" data-editor-color="fuchsia">Fuchsia</button>
                    <button type="button" data-editor-action="table">Table</button>
                </div>
                <div id="editor" class="rich-editor-canvas" data-placeholder="Start writing"></div>
                <textarea id="source" class="rich-editor-source"></textarea>
                <span data-editor-count></span>
                <span data-editor-status></span>
            </div>
        </form>
    `);
    await page.addScriptTag({ path: editorBundle });

    const root = page.locator('[data-rich-editor]');
    const canvas = page.locator('#editor .ProseMirror');
    const source = page.locator('#source');
    await expect(root).toHaveAttribute('data-editor-ready', '1');

    await canvas.click();
    await page.keyboard.insertText('First paragraph');
    await page.keyboard.press('Enter');
    await page.keyboard.insertText('Second paragraph');
    await expect(source).toHaveValue('<p>First paragraph</p><p>Second paragraph</p>');

    await page.getByRole('button', { name: 'Center' }).click();
    await expect(source).toHaveValue('<p>First paragraph</p><p data-align="center">Second paragraph</p>');

    await canvas.press('Control+A');
    await page.getByRole('button', { name: 'Bold' }).click();
    await page.getByRole('button', { name: 'Fuchsia' }).click();
    await expect(source).toHaveValue(/<strong><span data-color="fuchsia">First paragraph<\/span><\/strong>/);
    await expect(source).toHaveValue(/<strong><span data-color="fuchsia">Second paragraph<\/span><\/strong>/);

    await page.getByRole('button', { name: 'Table' }).click();
    await expect(source).toHaveValue(/<table/);
    await expect(source).not.toHaveValue(/style=/);
});

test('compiled content editor round-trips an image-only document without losing metadata', async ({ page }) => {
    await page.setContent(`
        <form id="content-form">
            <div data-rich-editor data-required="1" data-editor-label="Page text">
                <div class="rich-editor-toolbar"></div>
                <div id="editor" class="rich-editor-canvas"></div>
                <textarea id="source" class="rich-editor-source"><figure data-align="center" data-size="medium"><img src="/uploads/pages/content/2026/07/example.webp" alt="Map"><figcaption>Server map</figcaption></figure></textarea>
                <span data-editor-count></span>
                <span data-editor-status></span>
            </div>
        </form>
    `);
    await page.addScriptTag({ path: editorBundle });

    const source = page.locator('#source');
    await expect(source).toHaveValue(/<figure data-align="center" data-size="medium">/);
    await expect(source).toHaveValue(/alt="Map"/);
    await expect(source).toHaveValue(/<figcaption>Server map<\/figcaption>/);

    const submitWasAllowed = await page.locator('#content-form').evaluate((form) => form.dispatchEvent(
        new SubmitEvent('submit', { bubbles: true, cancelable: true }),
    ));
    expect(submitWasAllowed).toBe(true);
});


test('compiled content editor accepts a table-only required document', async ({ page }) => {
    await page.setContent(`
        <form id="content-form">
            <div data-rich-editor data-required="1" data-editor-label="Page text">
                <div class="rich-editor-toolbar">
                    <button type="button" data-editor-action="table">Table</button>
                </div>
                <div id="editor" class="rich-editor-canvas"></div>
                <textarea id="source" class="rich-editor-source"></textarea>
                <span data-editor-count></span>
                <span data-editor-status></span>
            </div>
        </form>
    `);
    await page.addScriptTag({ path: editorBundle });

    await page.getByRole('button', { name: 'Table' }).click();
    await expect(page.locator('#source')).toHaveValue(/<table/);

    const submitWasAllowed = await page.locator('#content-form').evaluate((form) => form.dispatchEvent(
        new SubmitEvent('submit', { bubbles: true, cancelable: true }),
    ));
    expect(submitWasAllowed).toBe(true);
});
