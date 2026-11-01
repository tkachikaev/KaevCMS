# Shop 1.0.0 — iteration 1

This bundled module is the initial Shop foundation for KaevCMS 0.57.0+.

- The module can be installed and enabled through the existing **Modules** administration flow.
- It adds an admin overview using existing admin components and CSS, with read-only access for auditors; Editors have no access.
- Schema: `module_shop_settings` and `module_shop_server_settings`. The global `sales_enabled` and per-server `enabled` flags default to `false`; there are deliberately no routes to turn on sales during iteration 1.
- No player storefront, checkout, Wallet operation, inventory grants or game queue integration exists in this iteration.
- Catalog, bundle and order schema will be added by **new immutable migrations** in the next iterations; do not modify this migration after deployment.

## Installing

The owner enables `shop` under **Modules**, which applies its pending migrations through the normal module flow. This does not allow sales. Disabling the module removes its administration route without deleting settings.

## Next

Iteration 2: GameServer selection, tabs and categories using the existing CMS design tokens and UI components. Separate migrations will introduce their tables.
