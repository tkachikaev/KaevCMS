# Shop

Shop sells items and bundles for CMS wallet coins. Each game server has its own catalogue.

## Shop catalogue redesign (stage 1/4)

The target structure is **GameServer → section → product**. A product will
contain one or more item IDs sharing a single price; separate categories and
bundle creation modes will disappear from the UI in later stages.

This stage adds a direct product-to-section relationship and optional RU/EN
descriptions. Existing tabs keep their IDs and become sections. Legacy
categories, prices, item lists and completed purchases remain intact.
**The admin interface still uses the old flow until stage 2.** After upgrading,
apply the pending module migration under **Modules**. Back up an existing
installation's database before applying the migration.

## Setup

1. Enable Shop and apply pending migrations under **Modules**.
2. Open **Shop** and select a game server.
3. Create a tab, category and products. Provide RU/EN names, an integer coin price, item IDs and quantities. Bundles contain at most 100 distinct item IDs. Empty or invalid bundles are not published.
4. Enable **Allow purchases on this server** in the selected server settings.
5. Enable **Accept shop purchases** in general settings.

Both switches start disabled. Activation requires no PHP, environment or database edits. Visibility is separate: hidden products, categories and tabs cannot be purchased.

Owner and Administrator may configure Shop. Auditor has read-only access to settings and history; Editor has no access.

## Purchases

Purchases debit wallet coins and deliver items to **Web Inventory**. Players initiate in-game transfer from there separately; the game-server administrator configures the queue consumer.

The server determines prices and contents. Debit, inventory grant, completed purchase and mandatory audit commit together. Retrying the same operation does not charge twice. Insufficient funds, blocked wallets and inactive users cannot buy.

## History

**My purchases** is available at `/modules/shop/history`. Players can read only their own purchases. Names, prices and contents are retained as purchased even after catalogue changes or product deletion.

Stopping sales keeps history accessible. Disabling the module preserves records but removes its pages until re-enabled. Refunds, manual re-grants and editing completed purchases are not implemented.

## Before opening sales

Verify a test purchase and Web Inventory delivery on an isolated installation. Never use an existing user database for integration tests: `tests/Integration/Shop/mysql-purchase-concurrency.php` runs `migrate:fresh` and requires a dedicated MySQL/MariaDB `shop_test` database with explicit testing permissions.
