# Donations and YooMoney

The bundled Donations module sells CMS wallet coins through YooMoney and can exchange coins for game items. Coins are credited to the player's [wallet](WALLET.md).

## Configure from the administration panel

1. Deploy KaevCMS at a **public HTTPS address** and enable Donations.
2. Under **Donations → Currencies and prices**, configure the RUB price per coin.
3. Under **Donations → Payment systems → YooMoney**, enter the wallet number and HTTP notification secret. The wallet number is visible after saving; the secret remains encrypted and is shown only as a saved-password indicator.
4. Copy the callback URL shown by the CMS into your YooMoney wallet's HTTP notification settings.
5. Turn on **Accept payments through YooMoney** and save. The CMS then checks production mode with debugging off, a public HTTPS address, MySQL/MariaDB, the YooMoney credentials and an active RUB price. If everything is ready, payments open for all players and the top-up page appears in the player account automatically. Otherwise the page lists what to fix and payments stay off.

No donation-specific `.env` editing or Artisan commands are needed.

## Payment modes

Payment acceptance has three states. The current state is shown on the Donations overview:

- **Off** (*Payments are off*) — players cannot start new payments. This is the default for new installations.
- **Selected player only** (*Pilot payment mode*) — real payments are available to one chosen player, for a control payment before opening payments to everyone.
- **All players** (*Payments are enabled*) — real payments are available to every player.

The YooMoney page has one switch: on means **All players**, off means **Off**. There is no separate test/live selector. The **Selected player only** mode cannot be chosen on the current page; it can remain only from version 0.50.11, and saving the YooMoney page replaces it with on or off.

Turning payments off stops new checkouts only. Signed confirmations for payments that were already started continue to be processed, and orders, history and saved confirmations are kept.

The **Test** button next to the notification address (shown when the site is opened at a public HTTPS address) checks the HTTP notification address and secret without a payment. The window then waits up to 5 minutes for a test notification: press "Test" in the HTTP notification settings on the YooMoney site. When the signature matches, the window reports success and saves the settings. When a notification with a wrong signature arrives, the window shows "The secret does not match" at once but keeps waiting: the notification address is open to anyone, and a forged request must not spoil the test. A correctly signed YooMoney notification that follows still counts. Real payments are handled as usual meanwhile, and test notifications never credit coins.

## Localhost cannot receive real callbacks

`127.0.0.1` is not publicly reachable by YooMoney. Genuine transfers with automatic crediting require a public HTTPS website and working MySQL/MariaDB. Local automated tests can validate the form, prices and signatures but cannot substitute for receipt of a real remote notification. The CMS displays this limitation when the operator attempts to enable payments; do not bypass signature verification.

## Payment safety

The server calculates the order amount, and it cannot be changed afterwards. Signed HMAC-SHA256 notifications must match the order and the amount debited from the payer (`withdraw_amount`); `amount` records what the receiver got after fees. Only a signed notification for a matching order credits coins, exactly once, through the wallet. Secrets are protected by the application encryption key: keep this key when upgrading. Before taking payments for game currency, check that your YooMoney wallet's terms of use allow it.

## Direct CMS coin exchange into game items

In Donations, open **Transfer coins to game**. For each game server specify its in-game item ID, the number of items **per 1 CMS coin**, and enable the exchange. For example `item_id=90000` at `10` gives a player 50 units of item 90000 for 5 CMS coins; use `1` for a 1:1 conversion. The feature defaults to off.

The player selects a game server and a coin quantity. One atomic primary-database transaction debits the wallet and grants the selected item to **Web Inventory**, with audit and retry protection. Players can then send items to a character using the existing Web Inventory → `kaev_reward_queue` workflow. The CMS does not directly modify game-item tables or guarantee downstream game delivery.

The exchange works independently from YooMoney and requires no `.env` edits. Disabling it does not erase grant or wallet history. Failed exchanges roll back coin debits.
