# Shop 1.0.1 — iteration 2 (development)

Shop is an **admin-only catalog editor**. It is not a storefront and does not debit coins or award items. All sales remain disabled even if old settings contain `true`.

- I1 (`2026_11_01_010000`): global and per-GameServer settings. This migration remains unchanged.
- I2 (`2026_11_01_020000`): `module_shop_tabs` and `module_shop_categories`, titles in RU/EN, ordering and visibility flags. Categories belong to a tab; tabs belong to exactly one GameServer.
- Owner and Administrator can add/edit/delete tabs and categories. Auditor can only view; Editor has no access.
- A tab cannot be deleted while it contains categories. Deleting a GameServer with catalog tabs is blocked by the module dependency guard and the database foreign key; empty per-server settings may be removed.
- Administrative changes are transactional and written to the common audit log; audit write failure rolls back the operation.
- Views use the standard `x-admin.*`, `admin-card-row`, `content-list`, `admin-overview`, `notice` and `button` styles; no new stylesheet or JavaScript.
- There are **no account, checkout, wallet, inventory or game-database routes**.

## Installation and upgrade

Enable/update Shop through **Modules** and apply its pending migrations using the standard admin workflow. Existing settings are preserved. New tables are additive; do not rewrite the I1 migration.

## Next

Iteration 3: products/bundles and prices attached to categories. Checkout comes later as a separate audited operation.
