# Shop

Shop sells items and bundles for CMS wallet coins. Each game server has its own catalogue.

## Shop redesign (stage 2/4)

The catalog now uses **GameServer → Section → Product**. In administration, create and open a section and click **New product**. One modal editor accepts RU/EN names and descriptions, one total coin price and one or more item ID + quantity rows. One row is an ordinary product; multiple rows form a set. Limit: 100 distinct IDs. Administration shows compact rows; the redesigned player cards are planned for stage 3.

Old tabs retain their IDs and become sections. New products no longer need an internal category. Existing legacy products, hidden-category visibility, bundle contents and completed purchases remain intact.

After **backing up the database**, apply the pending Shop migrations under **Modules**: `2026_11_01_050000` (section backfill) and `2026_11_02_010000` (optional legacy category). Do not delete the old category table manually; legacy visibility still depends on it.

## Setup

1. Enable Shop and apply pending migrations under **Modules**.
2. Open **Shop** and select a game server.
3. Create a **section**, then choose **New product**. Set RU/EN name and description, integer coin price and one or more game item IDs with quantities. A product can include up to 100 distinct IDs.
4. Enable **Allow purchases on this server** in the selected server settings.
5. Enable **Accept shop purchases** in general settings.

Both switches start disabled. Activation requires no PHP, environment or database edits. Product visibility is separate: hidden products and sections cannot be purchased. Legacy items in hidden categories also remain unavailable.

Owner and Administrator may configure Shop. Auditor has read-only access to settings and history; Editor has no access.

## Purchases

Purchases debit wallet coins and deliver items to **Web Inventory**. Players initiate in-game transfer from there separately; the game-server administrator configures the queue consumer.

The server determines prices and contents. Debit, inventory grant, completed purchase and mandatory audit commit together. Retrying the same operation does not charge twice. Insufficient funds, blocked wallets and inactive users cannot buy.

## History

**My purchases** is available at `/modules/shop/history`. Players can read only their own purchases. Names, prices and contents are retained as purchased even after catalogue changes or product deletion.

Stopping sales keeps history accessible. Disabling the module preserves records but removes its pages until re-enabled. Refunds, manual re-grants and editing completed purchases are not implemented.

## Before opening sales

Verify a test purchase and Web Inventory delivery on an isolated installation. Never use an existing user database for integration tests: `tests/Integration/Shop/mysql-purchase-concurrency.php` runs `migrate:fresh` and requires a dedicated MySQL/MariaDB `shop_test` database with explicit testing permissions.
