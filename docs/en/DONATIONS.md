# YooMoney — KaevCMS 0.50.16 / Donations 1.16.1

## Configure from the administration panel

1. Deploy KaevCMS at a **public HTTPS address** and enable Donations.
2. Under **Donations → Currencies and prices**, configure the RUB price per coin.
3. Under **Donations → Payment systems → YooMoney**, enter the wallet number and HTTP notification secret. The wallet number is visible after saving; the secret remains encrypted and is shown only as a saved-password indicator.
4. Copy the callback URL shown by the CMS into your YooMoney wallet's HTTP notification settings.
5. Turn on **Accept payments through YooMoney** and save. The player top-up page becomes visible automatically. Players select coins and continue directly to checkout.

There is no test/live selector, pilot player setup or donation-specific `.env` editing for normal operation. Turning off this one control stops new checkouts while signed callbacks for existing payments continue to work.

## Localhost cannot receive real callbacks

`127.0.0.1` is not publicly reachable by YooMoney. Genuine transfers with automatic crediting require a public HTTPS website and working MySQL/MariaDB. Local automated tests can validate the form, prices and signatures but cannot substitute for receipt of a real remote webhook. The CMS displays this limitation when the operator attempts to enable payments; do not bypass signature verification.

The server calculates the immutable order amount. Signed HMAC-SHA256 notifications must match the order and gross payer debit (`withdraw_amount`); `amount` records the receiver net. Verified credit is idempotent and goes through WalletGateway. Preserve the application's encryption key across upgrades and verify your YooMoney wallet's terms of use before taking payments for game currency.

## Direct CMS coin exchange into game items

In Donations, open **Transfer coins to game**. For each game server specify its in-game item ID, the number of items **per 1 CMS coin**, and enable the exchange. For example `item_id=90000` at `10` gives a player 50 units of item 90000 for 5 CMS coins; use `1` for a 1:1 conversion. The feature defaults to off.

The player selects a game server and a coin quantity. One atomic primary-database transaction debits Wallet and grants the selected item to **Web Inventory**, with audit and retry idempotency. Players can subsequently send items to a character using the existing Web Inventory → `kaev_reward_queue` workflow. The CMS does not directly modify game-item tables or guarantee downstream game delivery.

The exchange works independently from YooMoney and requires no `.env` edits. Disabling it does not erase grant or Wallet history. Failed exchanges roll back coin debits.
