import { expect, test } from '@playwright/test';
import { submitLogin } from '../support/authentication.mjs';
import { gotoWithLocalNetworkRetry } from '../support/navigation.mjs';

for (const variant of [
    { locale: 'ru', scheme: 'light', width: 1440, login: 'Логин-сервер', game: 'Игровой сервер', gameMapping: '2. Поля сайта' },
    { locale: 'en', scheme: 'dark', width: 390, login: 'Login server', game: 'Game server', gameMapping: '2. Website fields' },
]) {
    test(`login driver can be configured, renamed and saved on ${variant.width}px (${variant.locale}, ${variant.scheme})`, async ({ page }, testInfo) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setViewportSize({ width: variant.width, height: 1000 });
        await gotoWithLocalNetworkRetry(page, '/admin/login');
        await page.locator('#email').fill(process.env.PLAYWRIGHT_ADMIN_EMAIL || 'browser-admin@example.test');
        await page.locator('#password').fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'BrowserPassword123!');
        await submitLogin({
            page, postPath: '/admin/login', label: 'Administrator login',
            submit: () => page.locator('button.primary-button[type=submit]').click(),
        });
        await gotoWithLocalNetworkRetry(page, '/admin/settings/drivers');
        if (variant.locale === 'en') {
            await page.locator('[data-language-menu] summary').click();
            await page.locator('form[action$="/language/en"] button').click();
        }
        await expect(page.getByRole('button', { name: variant.login, exact: true })).toBeVisible();
        await page.evaluate(scheme => window.KaevCMSAdminAppearance.apply(scheme), variant.scheme);
        await expect(page.locator('html')).toHaveAttribute('data-admin-color-scheme', variant.scheme);
        const firstCard = page.locator('.ud-profile').first();
        const menuSummary = firstCard.locator('.ud-menu summary');
        const dangerColor = await page.evaluate(() => {
            const probe = document.createElement('span');
            probe.style.color = 'var(--admin-danger)';
            document.body.append(probe);
            const color = getComputedStyle(probe).color;
            probe.remove();
            return color;
        });
        // Row actions: one main button, the rest in a neutral menu; only the delete entry is red.
        await expect(firstCard.locator('.ud-actions > button')).toHaveCount(1);
        await expect(menuSummary).not.toHaveCSS('color', dangerColor);
        await menuSummary.click();
        await expect(firstCard.locator('button[wire\\:click^="askDelete"]')).toBeVisible();
        await expect(firstCard.locator('button[wire\\:click^="askDelete"]')).toHaveCSS('color', dangerColor);
        await expect(firstCard.locator('button[wire\\:click^="exportProfile"]')).not.toHaveCSS('color', dangerColor);
        // Opening another row's menu closes this one; Escape and an outside click close it too.
        if (variant.width > 780 && await page.locator('.ud-profile').count() > 1) {
            await page.locator('.ud-profile').nth(1).locator('.ud-menu summary').click();
            await expect(page.locator('.ud-menu[open]')).toHaveCount(1);
        }
        await page.keyboard.press('Escape');
        await expect(page.locator('.ud-menu[open]')).toHaveCount(0);
        await menuSummary.click();
        await page.locator('h1').first().click();
        await expect(page.locator('.ud-menu[open]')).toHaveCount(0);
        await firstCard.locator('button[wire\\:click^="editProfile"]').click();
        await expect(page.locator('.ud-login-editor')).toBeVisible();
        await expect(page.locator('.ud-login-editor input[type=checkbox]')).toHaveCount(0);
        await expect(page.locator('.ud-login-editor button[wire\\:click="setSection(\'schema\')"]')).toHaveCount(0);
        await page.locator('.ud-login-editor button[wire\\:click="closeEditor"]').first().click();

        await page.locator('button[wire\\:click="createProfile(\'login\')"]').click();
        await page.locator('#ud-login-template').selectOption('rusacis');
        await page.locator('button[wire\\:click="useLoginTemplate"]').click();
        await expect(page.locator('#ud-login-password-scheme')).toHaveValue('bcrypt_2a');
        // The connection takes its encoding from the server settings, so the editor offers none.
        await expect(page.locator('#ud-login-charset, #ud-login-collation')).toHaveCount(0);
        const name = `Editor browser ${variant.locale} ${Date.now()}`;
        await page.screenshot({ path: testInfo.outputPath('login-settings.png'), fullPage: true });
        await page.locator('#ud-login-name').fill(name);
        await page.locator('.ud-login-editor button[type=submit]').click();
        await expect(page.locator('.ud-login-mappings')).toBeVisible();
        await page.locator('#ud-login-table-login').fill('player_accounts');
        await page.locator('#ud-login-table-login').press('Tab');
        await expect(page.locator('#ud-login-table-password')).toHaveValue('player_accounts');
        await page.locator('#ud-login-column-login').fill('username');
        await page.locator('#ud-login-column-password').fill('secret_hash');
        await expect(page.locator('#ud-login-column-password')).toHaveAttribute('required', '');
        await page.locator('.ud-login-editor .ud-advanced > summary').click();
        await page.locator('button[wire\\:click="addRegistrationRow"]').click();
        const registrationRow = page.locator('.ud-registration-row').first();
        await expect(registrationRow.locator('input')).toHaveCount(3);
        await expect(registrationRow.locator('input').nth(0)).toHaveValue('player_accounts');
        await expect(registrationRow.locator('input').nth(2)).toHaveValue('0');
        await registrationRow.locator('input').nth(1).fill('custom_flag');
        await registrationRow.locator('input').nth(2).fill('3');
        await page.screenshot({ path: testInfo.outputPath('login-mappings.png'), fullPage: true });
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
        // Error messages come from the CMS locale, not native browser validation.
        await page.locator('#ud-login-column-password').fill('');
        await page.locator('.ud-login-editor button[type=submit]').click();
        await expect(page.locator('.ud-field-error')).toContainText(variant.locale === 'ru' ? 'Заполните поле' : 'Enter');
        await page.locator('#ud-login-column-password').fill('secret_hash');
        await page.locator('.ud-login-editor button[type=submit]').click();
        const created = page.locator('.ud-profile').filter({ hasText: name });
        await expect(created).toBeVisible();
        await created.locator('button[wire\\:click^="editProfile"]').click();
        await page.locator('.ud-login-editor button[wire\\:click="nextLoginStep"]').click();
        await expect(page.locator('#ud-login-table-login')).toHaveValue('player_accounts');
        await expect(page.locator('#ud-login-column-password')).toHaveValue('secret_hash');
        await expect(page.locator('.ud-registration-row input').nth(1)).toHaveValue('custom_flag');
        await expect(page.locator('.ud-registration-row input').nth(2)).toHaveValue('3');
        await page.locator('.ud-login-editor button[wire\\:click="closeEditor"]').first().click();
        await created.locator('.ud-menu summary').click();
        await created.locator('button[wire\\:click^="askDelete"]').click();
        await page.locator('button[wire\\:click="deleteProfile"]').click();
        await expect(created).toHaveCount(0);

        await page.getByRole('button', { name: variant.game, exact: true }).click();
        await page.locator('button[wire\\:click="createProfile(\'game\')"]').click();
        await page.locator('#ud-game-template').selectOption('rusacis');
        await page.locator('button[wire\\:click="useGameTemplate"]').click();
        await expect(page.locator('#ud-game-name')).toHaveValue(/RUSaCis/);
        const gameName = `Game editor ${variant.locale} ${Date.now()}`;
        await page.locator('#ud-game-name').fill(gameName);
        await expect(page.locator('.ud-game-editor input[type=checkbox]')).toHaveCount(0);
        await page.screenshot({ path: testInfo.outputPath('game-settings.png'), fullPage: true });
        await page.locator('.ud-game-editor button[type=submit]').click();
        await expect(page.locator('input[wire\\:model="draft.config.characters.fields.id.column"]')).toHaveValue('obj_Id');
        await page.locator('[data-game-group=game_basic] > summary').click();
        const mainTable = page.locator('input[wire\\:model\\.blur="draft.config.characters.table"]').first();
        await mainTable.fill('players');
        await mainTable.press('Tab');
        await page.locator('input[wire\\:model="draft.config.characters.fields.id.column"]').fill('player_id');
        await page.locator('input[wire\\:model="draft.config.characters.fields.level.column"]').fill('player_level');
        await page.locator('[data-game-group=game_additional] > summary').click();
        const title = page.locator('[wire\\:key="game-field-title"]');
        await title.locator('summary').click();
        await title.locator('select').first().selectOption('related');
        await title.locator('select').nth(1).selectOption('clan');
        await expect(title.locator('input').first()).toHaveValue('clan_data');
        await title.locator('input[wire\\:model="draft.config.characters.fields.title.column"]').fill('clan_name');
        await page.locator('[data-game-group=game_statistics] > summary').click();
        const reputation = page.locator('[wire\\:key="game-field-reputation"]');
        await reputation.locator('summary').click();
        await reputation.locator('select').first().selectOption('choice');
        await reputation.locator('input[wire\\:model="draft.config.characters.fields.reputation.columns.0"]').fill('karma');
        await reputation.locator('input[wire\\:model="draft.config.characters.fields.reputation.columns.1"]').fill('reputation');
        await page.screenshot({ path: testInfo.outputPath('game-mappings.png'), fullPage: true });
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
        await page.locator('button[wire\\:click="nextGameStep(\'features\')"]').first().click();
        await expect(page.locator('input[wire\\:model="draft.config.relations.heroes.local_column"]')).toHaveValue('player_id');
        await expect(page.locator('input[wire\\:model\\.blur="draft.config.relations.leader.table"]').first()).toHaveValue('players');
        await page.locator('.ud-game-group').filter({ has: page.locator('input[wire\\:model="draft.config.features.heroes.count_column"]') }).locator('summary').first().click();
        await page.locator('input[wire\\:model="draft.config.features.heroes.count_column"]').fill('hero_total');
        await page.screenshot({ path: testInfo.outputPath('game-features.png'), fullPage: true });
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
        await page.locator('.ud-game-editor button[type=submit]').click();
        const gameCard = page.locator('.ud-profile').filter({ hasText: gameName });
        await expect(gameCard).toBeVisible();
        await gameCard.locator('button[wire\\:click^="editProfile"]').click();
        await page.locator('button[wire\\:click="nextGameStep(\'mapping\')"]').click();
        await expect(page.locator('input[wire\\:model="draft.config.characters.fields.id.column"]')).toHaveValue('player_id');
        await expect(page.locator('[wire\\:key="game-field-title"] input').first()).toHaveValue('clan_data');
        // A key/value source must remain usable after changing its kind and selecting a new relation.
        await page.locator('[data-game-group=game_additional] > summary').click();
        const keyValueTitle = page.locator('[wire\\:key="game-field-title"]');
        await keyValueTitle.locator('select').first().selectOption('eav');
        await page.locator('button[wire\\:click="nextGameStep(\'features\')"]').first().click();
        await page.locator('button[wire\\:click="addGameRelation"]').click();
        const stats = page.locator('[wire\\:key="game-relation-relation_1"]');
        await stats.locator('input').nth(0).fill('character_stats');
        await stats.locator('input').nth(1).fill('player_id');
        await stats.locator('input').nth(2).fill('char_id');
        await stats.locator('summary').click();
        await stats.locator('select[wire\\:model="draft.config.relations.relation_1.cardinality"]').selectOption('many');
        await page.locator('button[wire\\:click="setSection(\'mapping\')"]').first().click();
        await page.locator('[data-game-group=game_additional] > summary').click();
        await keyValueTitle.locator('select').nth(1).selectOption('relation_1');
        await keyValueTitle.locator('input[wire\\:model="draft.config.characters.fields.title.key_column"]').fill('param');
        await keyValueTitle.locator('input[wire\\:model="draft.config.characters.fields.title.value_column"]').fill('value');
        await keyValueTitle.locator('input[wire\\:model="draft.config.characters.fields.title.key_value"]').fill('title');
        await expect(keyValueTitle.locator('input').first()).toHaveValue('character_stats');
        await page.locator('.ud-game-editor button[type=submit]').click();
        await expect(gameCard).toBeVisible();
        await gameCard.locator('.ud-menu summary').click();
        const [download] = await Promise.all([page.waitForEvent('download'), gameCard.locator('button[wire\\:click^="exportProfile"]').click()]);
        const chunks = [];
        for await (const chunk of await download.createReadStream()) chunks.push(chunk);
        const exported = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        expect(exported.config.characters.fields.title.kind).toBe('eav');
        expect(exported.config.capabilities).toEqual(['characters', 'castles']);
        expect(Object.keys(exported.config.rankings)).toHaveLength(0);
        await gameCard.locator('button[wire\\:click^="editProfile"]').click();
        await page.locator('.ud-game-editor button[wire\\:click="closeEditor"]').first().click();
        await gameCard.locator(".ud-menu summary").click();
        await gameCard.locator('button[wire\\:click^="askDelete"]').click();
        await page.locator('button[wire\\:click="deleteProfile"]').click();
        await expect(gameCard).toHaveCount(0);
        expect(errors).toEqual([]);
    });
}
