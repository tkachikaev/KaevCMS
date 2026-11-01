# Shop — pre-release

**Status:** Shop 1.0.3 — DEV. Checkout code exists, but Laravel, real test MySQL/MariaDB concurrency and browser acceptance have not passed yet. **Do not enable sales.**

## Catalogue and purchases

An Owner or Administrator can create GameServer-specific tabs, categories and products. Products may be one item (game item ID and quantity) or a bundle containing several items. RU/EN names, integer CMS coin prices, order and visibility are configurable. Empty bundles are not published.

Checkout is designed to debit coins through the existing WalletGateway, grant items to **Web Inventory**, store an immutable price/item snapshot and write an audit record in one transaction. A replay of the same UUID must return the previous purchase without another debit. **Web Inventory is not direct character delivery**: the player initiates transfer later, and the game-server administrator configures the reward-queue consumer.

## Administration

1. On an isolated test installation, enable Shop under **Modules**.
2. Apply the module's pending migrations via **Modules**. Four additive migration batches are provided; applied migrations must remain unchanged.
3. Open **Shop** in administration and select a GameServer.
4. Add a tab, category and products. For a bundle, configure its constituent item IDs and quantities separately.
5. Set catalogue visibility and ordering. **This does not enable sales.**

Owner and Administrator can edit; Auditor is read-only; Editor has no access. The administration purchase journal only shows completed purchases. Manual refunds, re-grants and editing settled operations are not implemented.

## Player cabinet

Test catalogue URL: `/modules/shop` (authentication required). While sales are disabled, users may browse published products and prices but cannot buy. The account navigation link remains hidden until sales are enabled, except that prior buyers may retain it for access to history.

Purchase history: `/modules/shop/history`. Receipts retain the product name, price, server and items at purchase time. Players can see only their own purchases. The page links to Web Inventory.

## Why sales are disabled

Three independent controls are all initially off:

- trusted wallet source `config/wallet.php` → `enabled_sources.shop = false`;
- Shop global database setting `module_shop_settings.sales_enabled = false`;
- per-server database setting `module_shop_server_settings.enabled = false`.

**Do not edit production database settings or code to bypass the acceptance gate.** This DEV build does not offer a supported sales activation flow in administration. Activation requires a separate acceptance and owner decision.

## Release acceptance gate

On an isolated installation with dependencies installed from the approved `composer.lock`, run Shop Feature tests, Wallet/RewardInventory tests, both PowerShell quality scripts under `deployment/windows/`, and `tests/Integration/Shop/mysql-purchase-concurrency.php` on a dedicated real MySQL/MariaDB `shop_test` database with explicit test-only environment flags. **That script runs `migrate:fresh` and destroys data in the selected database.** Inspect all three account themes in both colour schemes and on mobile widths. Until all gates pass, the module remains DEV.
