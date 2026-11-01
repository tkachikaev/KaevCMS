# Shop 1.0.3 — iteration 4 (development)

Shop remains **admin-only in the UI**. I4 adds an internal purchase engine but **no public checkout route**. All sales remain disabled in shipped configuration (`config/wallet.php`: `shop = false`), and the database switches default to off.

- I1 (`2026_11_01_010000`): global and per-GameServer settings. This migration remains unchanged.
- I2 (`2026_11_01_020000`): `module_shop_tabs` and `module_shop_categories`, titles in RU/EN, ordering and visibility flags. Categories belong to a tab; tabs belong to exactly one GameServer.
- I3 (`2026_11_01_030000`): `module_shop_products` (single items or bundles, RU/EN labels, positive integer coin prices, sort/visibility, server-bound category), `module_shop_bundle_items` (multiple IDs and positive quantities; duplicate IDs rejected). A bundle may be saved empty while being configured; no checkout endpoint exposes it.
- Owner and Administrator can add/edit/delete tabs, categories, products and bundle entries. Auditor can only view; Editor has no access.
- A category cannot be deleted while it has products, and a tab cannot be deleted while it contains categories. Deleting a GameServer with catalog tabs is blocked by the module dependency guard and the database foreign key; empty per-server settings may be removed.
- Administrative changes are transactional and written to the common audit log; audit write failure rolls back the operation.
- Views use the standard `x-admin.*`, `admin-card-row`, `content-list`, `admin-overview`, `notice` and `button` styles; no new stylesheet or JavaScript.
- There are **no account, checkout, wallet, inventory or game-database routes**.

- I4 (`2026_11_01_040000`): `module_shop_purchases` stores immutable completed purchases (UUID, server, price, item snapshot, wallet entry and inventory grant). `ShopPurchaseService` uses the existing WalletGateway, RewardInventoryService and audit in one transaction; replay is idempotent. No direct game database writes.

## Installation and upgrade

Enable/update Shop through **Modules** and apply its pending migrations using the standard admin workflow. Existing settings are preserved. New tables are additive; do not rewrite the I1 migration.

## Next

Iteration 5: integrate safe player storefront and checkout after tests, with existing account styles. Keep trusted wallet source and sales settings disabled until explicit acceptance.
