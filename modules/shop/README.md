# Shop 1.0.5

Shop provides a separate item and bundle catalogue for each GameServer. Players spend CMS wallet coins and receive purchases in Web Inventory. Transferring items into the game remains a separate player action handled by the existing inventory delivery system.

## Catalog redesign (development, I2/4)

The administration catalog now uses **GameServer → Section → Product**. The former tabs are sections; products can contain **one or multiple items** at one total price. Open **Shop → GameServer**, select a section, then use **New product** to add localized names/descriptions, price, item IDs and quantities in one dialog. Adding an item row automatically makes a bundle. The administration displays a compact list; player cards will be redesigned in I3.

Migration `2026_11_01_050000_prepare_shop_sections.php` backfills existing `section_id` values without altering their original category or purchase records. Migration `2026_11_02_010000_shop_products_optional_legacy_category.php` allows new products to use a direct section instead of a nested category. **Apply both pending migrations manually in Modules** on a backed-up copy before using the new editor. Existing legacy categories remain in the database so old hidden categories stay hidden, and old purchases remain intact. New products have `category_id = null`. Old admin endpoints remain for compatibility but are no longer part of the new UI.

Server-side rules: only Owner/Administrator can write; the selected section must belong to the current GameServer; rows must contain 1–100 distinct valid IDs with positive quantities. One item is stored as `item`, multiple items as `bundle` for compatibility with checkout. Updates of price, contents and mandatory audit commit together, or all roll back.

Sales are disabled by default. Enable globally and for the appropriate GameServer only after acceptance on a test installation. The financial gate does not change with this redesign. Legacy hidden category visibility is enforced for both catalog browsing and checkout.

## Player cards and administrator preview (redesign I3)

Each product is displayed in a vertical card with its own localized name, description, price and existing game-item icons. A single item has one large icon; a bundle shows up to three icons with `+N` for further items. The details disclosure contains **every** configured item ID and quantity, even when the preview is shortened. Missing game assets use the existing neutral fallback. Icon lookup uses the actual GameServer profile and only resolves the first three item icons per product. The same artwork partial and card proportions are used in player and administrator views.

On the server administration page the selected section has a Cards/Table switch. Cards are the default and show the same preview as the player; the table is for compact management. Both modes retain the existing product editing dialog, server isolation and authorization. The switch is implemented through the existing external `shop-editor.js` and the current shared administration components; no inline script, third-party UI framework or extra nested catalog layer.

No schema or payment behavior changes in I3. Module remains version 1.0.5, sales remain disabled by default. Full theme/browser and SQL regression testing is required before a live release.

## Purchases and history

The checkout uses WalletGateway and RewardInventoryService. Wallet debit, purchase snapshot, inventory grant and the mandatory purchase audit commit in one transaction. A repeated request UUID returns the same completed purchase without another debit or grant. User, product visibility, balance and wallet blocking are checked on the server.

Players see only their own completed purchases at `/modules/shop/history`. Receipts retain the original product names, price and contents after catalogue changes or deletion. Stopping sales keeps this history available. Disabling the module preserves its database records; re-enable it to restore its routes.

Owner, Administrator and Auditor may read the administration purchase journal; Editor has no access. Refunds, manual re-grants and editing completed purchases are outside this module.

## Verification

Run `deployment/windows/quality.ps1` and `deployment/windows/browser-quality.ps1`. The browser suite includes `tests/browser/specs/shop.spec.mjs`. The opt-in `tests/Integration/Shop/mysql-purchase-concurrency.php` verifies independent concurrent requests on real MySQL/MariaDB, including repeated UUIDs, competing balances, cross-user UUID conflicts and migration preservation.

The integration script runs `migrate:fresh`. It requires an isolated database named `shop_test`, `APP_ENV=testing`, `SHOP_TEST_ALLOW_CONCURRENCY=1` and `SCHEMA_TEST_ALLOW=1`. Never run it against an existing user database. Do not open production sales before acceptance of the deployment.
