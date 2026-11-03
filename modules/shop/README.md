# Shop 1.0.6

Shop provides a separate item and bundle catalogue for each GameServer. Players spend CMS wallet coins and receive purchases in Web Inventory. Transferring items into the game remains a separate player action handled by the existing inventory delivery system.

## Catalogue editor

The catalogue is **GameServer → Section → Product** on one administration screen per server. Sections are listed on the left: they are created inline, reordered by drag and drop or the arrow keys on their handle, renamed per language and hidden in a small dialog. Products of the selected section are cards on the right. **Add product** opens a side panel: item rows like promo-code rewards (icon, ID, quantity), search by item name or ID, a language tab per enabled site language with name and description, price, section and visibility. One item names the product automatically in each language until the admin types a name; several items form a bundle (1–100 distinct IDs) that needs a typed name.

Forms and section switches update the page in place without losing the scroll position (`public/assets/admin/js/shop-editor.js`, delegated, no inline handlers); without JavaScript they work as ordinary forms and links. Item lookup and search are read-only JSON endpoints limited to administrators.

Migrations `2026_11_01_050000` and `2026_11_02_010000` turn the former tabs into sections and let products skip the legacy category. Migration `2026_11_03_010000` adds section and product translations and copies the existing RU/EN texts; the `name_ru`/`name_en` columns stay filled as a fallback and for purchase snapshots. **Apply pending migrations manually in Modules** on a backed-up database. Legacy categories stay in the database so old hidden categories remain hidden.

Server-side rules: only Owner/Administrator can write; the section must belong to the current GameServer; order updates must list exactly that server's sections or that section's products. Price, contents, translations and the mandatory audit commit together, or all roll back.

## Storefront

Players choose a server and a section (pill buttons, switched without a reload) and see equal-height cards with item icons, name, description and contents by item name. **Buy** opens a confirmation dialog with contents, price and the balance after purchase; the checkout form and its one-time request UUID are inside it. The result dialog lists the delivered items and links to Web Inventory. The account menu shows the shop whenever a catalogue is visible, even while sales are off, or when the player has purchase history.

Sales are disabled by default. Enable them globally and for the GameServer only after acceptance on a test installation.

## Purchases and history

The checkout uses WalletGateway and RewardInventoryService. Wallet debit, purchase snapshot, inventory grant and the mandatory purchase audit commit in one transaction. A repeated request UUID returns the same completed purchase without another debit or grant. User, product visibility, balance and wallet blocking are checked on the server.

Players see only their own completed purchases at `/modules/shop/history`. Receipts retain the original product names, price and contents after catalogue changes or deletion. Stopping sales keeps this history available. Disabling the module preserves its database records; re-enable it to restore its routes.

Owner, Administrator and Auditor may read the administration purchase journal; Editor has no access. Refunds, manual re-grants and editing completed purchases are outside this module.

## Verification

Run `deployment/windows/quality.ps1` and `deployment/windows/browser-quality.ps1`. The browser suite includes `tests/browser/specs/shop.spec.mjs`. The opt-in `tests/Integration/Shop/mysql-purchase-concurrency.php` verifies independent concurrent requests on real MySQL/MariaDB, including repeated UUIDs, competing balances, cross-user UUID conflicts and migration preservation.

The integration script runs `migrate:fresh`. It requires an isolated database named `shop_test`, `APP_ENV=testing`, `SHOP_TEST_ALLOW_CONCURRENCY=1` and `SCHEMA_TEST_ALLOW=1`. Never run it against an existing user database. Do not open production sales before acceptance of the deployment.
