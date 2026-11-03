# Shop

Shop sells items and bundles for CMS wallet coins. Each game server has its own catalogue: **game server → section → product**.

## Setup

1. Enable Shop in **Modules** and apply the pending migrations (back up the database before updating).
2. Open **Shop** and choose a game server. You can switch servers later with the button showing its name in the page header.
3. Type a name into **New section** on the left and press "+". Reorder sections by dragging the handle or with the arrow keys.
4. Click **Add product**. The product panel opens on the right:
   - under **What the player receives**, enter an item ID or find the item by name; the icon and name fill in automatically;
   - one item is a regular product, several items make a bundle for one price (up to 100 distinct items);
   - the product name in each language comes from the item name; you can overwrite it, after which it no longer changes by itself. A bundle needs a typed name;
   - the description is written by hand; languages are tabs, one for each enabled site language.
5. Turn on **Sales on this server** in the server page header and **Accept shop purchases** in the general shop settings.

Both switches start off. Players can still browse the catalogue, but nothing can be bought. Hidden products and sections are not shown to players; older products in hidden categories stay unavailable too.

Sections, visibility and products are saved without reloading the page, and the scroll position stays where it was. Without JavaScript everything still works through ordinary forms and links.

Owners and administrators can configure the shop. Auditors can view settings and history without changing them; editors have no access.

## Languages

Section names, product names and descriptions are stored for every enabled site language. When a language has no text, the fallback language is shown. Updating to 1.0.6 moves the existing Russian and English names and descriptions automatically with migration `2026_11_03_010000`.

## Purchases

In the account area the player picks a server and section, clicks **Buy** and confirms in a dialog that shows the contents, price and remaining balance. The coins are then debited and the items go to **Web Inventory**; the result dialog links straight to it. Players send items into the game from Web Inventory.

The server determines price and contents. Debit, delivery, the purchase record and the mandatory audit are committed together. Repeating the same operation never charges twice. Insufficient balance, a blocked wallet or a disabled user cannot buy.

## History

**My purchases** is available at `/modules/shop/history`. Players see only their own purchases. Names, price and contents stay as they were at payment, even after the product changes or is deleted; the receipt shows items with icons and names.

Stopping sales keeps the history available. Disabling the module keeps the records but turns its pages off until it is enabled again. Refunds, manual re-delivery and editing completed purchases are not provided.

## Before opening sales

Test a purchase and Web Inventory delivery on a separate installation. Do not run integration tests against a production database: `tests/Integration/Shop/mysql-purchase-concurrency.php` runs `migrate:fresh` and requires a dedicated MySQL/MariaDB database named `shop_test` with explicit test permissions.
