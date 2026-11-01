# Shop 1.0.3 — iteration 6 (development)

Shop now has a player-facing, authenticated storefront and POST checkout route. **Sales remain disabled** in the shipped configuration (`config/wallet.php`: `shop = false`), and database settings also default to off. The page is a browse-only catalogue until an explicitly reviewed later activation. The account sidebar link is hidden while sales are not enabled; direct GET is available for local visual verification.

- I1 (`2026_11_01_010000`): global and per-GameServer settings. This migration remains unchanged.
- I2 (`2026_11_01_020000`): `module_shop_tabs` and `module_shop_categories`, titles in RU/EN, ordering and visibility flags. Categories belong to a tab; tabs belong to exactly one GameServer.
- I3 (`2026_11_01_030000`): `module_shop_products` (single items or bundles, RU/EN labels, positive integer coin prices, sort/visibility, server-bound category), `module_shop_bundle_items` (multiple IDs and positive quantities; duplicate IDs rejected). A bundle may be saved empty while being configured; no checkout endpoint exposes it.
- Owner and Administrator can add/edit/delete tabs, categories, products and bundle entries. Auditor can only view; Editor has no access.
- A category cannot be deleted while it has products, and a tab cannot be deleted while it contains categories. Deleting a GameServer with catalog tabs is blocked by the module dependency guard and the database foreign key; empty per-server settings may be removed.
- Administrative changes are transactional and written to the common audit log; audit write failure rolls back the operation.
- Views use the standard `x-admin.*`, `admin-card-row`, `content-list`, `admin-overview`, `notice` and `button` styles; no new stylesheet or JavaScript.
- I5 adds `modules/shop` (GET) and `modules/shop/checkout` (POST, authenticated and rate-limited). The checkout calls I4 service; it cannot debit unless the trusted source, global setting, and server setting all allow sales. No direct game-database access.

- I4 (`2026_11_01_040000`): `module_shop_purchases` stores immutable completed purchases (UUID, server, price, item snapshot, wallet entry and inventory grant). `ShopPurchaseService` uses the existing WalletGateway, RewardInventoryService and audit in one transaction; replay is idempotent. No direct game database writes.

- I5 UI uses the existing shared account `account-tabs`, `reward-history-card`, `account-button`, `account-panel` components. No CSS/JS or account theme override was added. Items are filtered by published server/tab/category/product and hidden or incomplete bundles are excluded.
- Each purchase form receives its own fresh UUID; replay is handled by I4 and history. On success the user is directed to the existing Web Inventory. Client-side disabled buttons are only convenience; all authorization is checked in the service.

## Installation and upgrade

Enable/update Shop through **Modules** and apply its pending migrations using the standard admin workflow. Existing settings are preserved. New tables are additive; do not rewrite the I1 migration.

## Next

Iteration 6: purchase history and management. I4 financial tests and concurrency tests still require real execution before activation; current storefront cannot enable purchases by itself.

## I6: completed purchase history (read-only)

- `/modules/shop/history` lists the signed-in player's own purchases. `/modules/shop/history/{id}` displays immutable receipt snapshots. Both remain accessible with sales switched off and never expose other users' data.
- `/admin/extensions/shop/purchases` lists settled purchases with server/player/date filters; purchase details include wallet entry and inventory grant references. Owner, Administrator and Auditor may view; Editor cannot.
- No refund, replay, mutation, manual adjustment, migration or new CSS is included. This is **not** a full order lifecycle; only successful completed purchases exist in the I4 ledger.
- The sidebar Shop link may appear for an existing buyer even if sales are later disabled, so they can reach purchase history.
