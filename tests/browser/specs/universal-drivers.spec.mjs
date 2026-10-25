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
        await expect(page.locator('.ud-field-error')).toContainText(variant.locale === 'ru' ? 'Это поле нужно заполнить' : 'This field is required');
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
        const mainTable = page.locator('input[wire\\:model\\.live\\.blur="draft.config.characters.table"]').first();
        // A pending main-table update must not overwrite another field typed during its round trip.
        await page.route('**/livewire-*/update', async (route) => {
            const body = route.request().postDataJSON();
            if (body?.components?.some(component => component.updates?.['draft.config.characters.table'] === 'players')) {
                await new Promise(resolve => setTimeout(resolve, 350));
            }
            await route.continue();
        });
        await mainTable.fill('players');
        await mainTable.press('Tab');
        await expect(page.locator('input[wire\\:model="draft.config.characters.fields.id.column"]')).toBeDisabled();
        await page.locator('input[wire\\:model="draft.config.characters.fields.id.column"]').fill('player_id');
        await page.locator('input[wire\\:model="draft.config.characters.fields.level.column"]').fill('player_level');
        await page.locator('[data-game-group=game_additional] > summary').click();
        const rowTable = (field) => page.locator(`input[wire\\:model\\.live\\.blur="fieldTables.${field}"]`);
        const rowMenu = (field, option) => page.locator(`tr[wire\\:key="game-field-${field}"] .ud-menu summary`).click().then(
            () => page.locator(`tr[wire\\:key="game-field-${field}"] button[wire\\:click="changeFieldKind('${field}', '${option}')"]`).click(),
        );
        // Type the other table straight into the row: the link columns appear under it.
        await rowTable('title').fill('clan_data');
        await rowTable('title').press('Tab');
        const titleLink = page.locator('tr[wire\\:key="game-field-source-title"]');
        await expect(titleLink).toBeVisible({ timeout: 20_000 });
        await titleLink.locator('input').nth(0).fill('clanid');
        await titleLink.locator('input').nth(1).fill('clan_id');
        await page.locator('input[wire\\:model="draft.config.characters.fields.title.column"]').fill('clan_name');
        await page.locator('[data-game-group=game_statistics] > summary').click();
        await rowMenu('reputation', 'choice');
        const reputation = page.locator('tr[wire\\:key="game-field-source-reputation"]');
        await reputation.locator('input[wire\\:model="draft.config.characters.fields.reputation.columns.0"]').fill('karma');
        await reputation.locator('input[wire\\:model="draft.config.characters.fields.reputation.columns.1"]').fill('reputation');
        await page.screenshot({ path: testInfo.outputPath('game-mappings.png'), fullPage: true });
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
        await page.locator('button[wire\\:click="nextGameStep(\'features\')"]').first().click();
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
        await expect(page.locator('input[wire\\:model\\.live\\.blur="fieldTables.title"]')).toHaveValue('clan_data');
        // A key/value source must remain usable after changing its kind: its table is typed in the row too.
        await page.locator('[data-game-group=game_additional] > summary').click();
        await page.locator('tr[wire\\:key="game-field-title"] .ud-menu summary').click();
        await page.locator('tr[wire\\:key="game-field-title"] button[wire\\:click="changeFieldKind(\'title\', \'eav\')"]').click();
        const keyValueTitle = page.locator('tr[wire\\:key="game-field-source-title"]');
        await page.locator('input[wire\\:model\\.live\\.blur="fieldTables.title"]').fill('character_stats');
        await page.locator('input[wire\\:model\\.live\\.blur="fieldTables.title"]').press('Tab');
        await keyValueTitle.locator('input').nth(0).fill('player_id');
        await keyValueTitle.locator('input').nth(1).fill('char_id');
        await keyValueTitle.locator('input[wire\\:model="draft.config.characters.fields.title.key_column"]').fill('param');
        await page.locator('input[wire\\:model="draft.config.characters.fields.title.value_column"]').fill('value');
        await keyValueTitle.locator('input[wire\\:model="draft.config.characters.fields.title.key_value"]').fill('title');
        await expect(page.locator('input[wire\\:model\\.live\\.blur="fieldTables.title"]')).toHaveValue('character_stats');
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
