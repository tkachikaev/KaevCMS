# Shop 1.0.4

Shop provides a separate item and bundle catalogue for each GameServer. Players spend CMS wallet coins and receive purchases in Web Inventory. Transferring items into the game remains a separate player action handled by the existing inventory delivery system.

## Catalogue simplification (development, I1/4)

The simplified model is **GameServer → Section → Product**. Each product will
contain one or several game item IDs, and will have a localized name, description
and one total coin price. An existing tab is reused as the section, with its
original ID and physical table preserved.

Migration `2026_11_01_050000_prepare_shop_sections.php` adds direct `section_id`
and optional descriptions to products, and copies section IDs from existing
categories. Legacy categories, bundle components, purchases, Wallet state and
visibility are deliberately left untouched. **I1 is a compatibility stage**:
current admin and storefront still show categories. Do not delete them or enable
new section-only editing until I2 replaces the old UI and validation safely.

## Configuration

Enable Shop and apply pending migrations in **Modules**. In **Shop**, choose a GameServer, add tabs and categories, then configure products. A product contains one item or a bundle of up to 100 distinct item IDs. Prices are positive integer wallet coins.

Sales start disabled. To accept purchases, enable **Accept shop purchases** in general settings and **Allow purchases on this server** for the selected GameServer. These settings require Owner or Administrator permissions and are audited. No code edits or environment switches are needed. Hiding products or sections prevents purchases independently of the sales switches.

## Purchases and history

The checkout uses WalletGateway and RewardInventoryService. Wallet debit, purchase snapshot, inventory grant and the mandatory purchase audit commit in one transaction. A repeated request UUID returns the same completed purchase without another debit or grant. User, product visibility, balance and wallet blocking are checked on the server.

Players see only their own completed purchases at `/modules/shop/history`. Receipts retain the original product names, price and contents after catalogue changes or deletion. Stopping sales keeps this history available. Disabling the module preserves its database records; re-enable it to restore its routes.

Owner, Administrator and Auditor may read the administration purchase journal; Editor has no access. Refunds, manual re-grants and editing completed purchases are outside this module.

## Verification

Run `deployment/windows/quality.ps1` and `deployment/windows/browser-quality.ps1`. The browser suite includes `tests/browser/specs/shop.spec.mjs`. The opt-in `tests/Integration/Shop/mysql-purchase-concurrency.php` verifies independent concurrent requests on real MySQL/MariaDB, including repeated UUIDs, competing balances, cross-user UUID conflicts and migration preservation.

The integration script runs `migrate:fresh`. It requires an isolated database named `shop_test`, `APP_ENV=testing`, `SHOP_TEST_ALLOW_CONCURRENCY=1` and `SCHEMA_TEST_ALLOW=1`. Never run it against an existing user database. Do not open production sales before acceptance of the deployment.
