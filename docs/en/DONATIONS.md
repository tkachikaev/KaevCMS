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

Deleting a game server that only has an exchange rate also removes that rate. Exchange history, rewards and deliveries prevent server deletion and remain protected even when Donations is disabled. Other database-protected references produce a readable warning in the deletion dialog. The new migration changes only the rate's server relationship; existing users, wallets, operations and payment-provider credentials are preserved.

## Provider architecture (0.54.5 / Donations 1.16.3)

The shared payment services now support a reviewed provider extension contract, but **FreeKassa is not installed yet**. YooMoney is still the only bundled payment driver; its configured wallet, notification URL, and legacy checkout behavior are unchanged.

A trusted, explicitly installed driver must implement `RoutablePaymentProvider` to opt into checkout and callbacks. A callback reference is an untrusted routing hint, not payment proof: the driver's cryptographic verification must succeed before any durable receipt or Wallet credit. HTTP input never chooses the PHP driver class.

Previously sealed YooMoney evidence in `kaevcms-signed-yoomoney-v1` remains readable, including deferred credits after key rotation. Other reviewed drivers use the provider-bound `kaevcms-signed-provider-v2` evidence format authenticated with Laravel Crypt. No Wallet or database schema change was made.

**Iteration 1 covers architecture only.** FreeKassa credentials, its callback endpoint, administrative sandbox payments and live activation are deferred. The planned IP allowlist editor will not have a reset-to-defaults button.

## FreeKassa — staged integration (KaevCMS 0.55.0 / Donations 1.17.0)

The bundled `freekassa` driver implements the documented SCI signed payment form. The permanent core-owned `POST /payments/freekassa/notify` endpoint uses signature verification over the original amount string, exact integer minor-unit comparisons, and unique FreeKassa `intid` IDs.

**IMPORTANT: FreeKassa live money collection is deliberately disabled at the driver level in this build.** Admin sandbox checkout has not been built yet. The provider rejects `live` mode, and test callbacks never credit any wallet. Do NOT use this build to accept FreeKassa payments.

FreeKassa's callback `CUR_ID` identifies a payment method, not a cryptographically proven order currency or status. Server-to-server verification, notification IP/trusted proxy controls, and an admin-only external test checkout are required before unlocking live. The provider test mode must also be enabled in FreeKassa's merchant dashboard.

The planned admin IP editor will not include a restore-default-IP button. Official SCI documentation: https://docs.freekassa.net/.

## FreeKassa callback IP security (KaevCMS 0.55.1 / Donations 1.17.1)

Go to **Donations → Payment providers → FreeKassa** in the admin panel. Configure merchant ID, payment secrets, currencies and permitted callback source IPs. The selectable notification URL is `POST /payments/freekassa/notify` on the public HTTPS site. Set that URL and POST method in FreeKassa's merchant panel, and enable the merchant's test mode separately. Merchant approval may be required.

- The initial editor list matches vendor SCI docs as of 2026-10-27: `168.119.157.136`, `168.119.60.227`, `178.154.197.79`, `51.250.54.238`. This is **not automatically synced**; verify the latest addresses at https://docs.freekassa.net/.
- IPs can be added or removed, one per line. Once saved, only the merchant's stored list is used, including deletions. There is **no restore-default-IP button or IP security disable switch**.
- If traffic passes through Cloudflare or nginx, open **Trusted proxies** and specify only the actual proxy peers (IP or CIDR). A proxy must append the true client address to `X-Forwarded-For`. Untrusted TCP peers cannot spoof this header.
- Empty source-IP lists reject all callbacks. The provider must be disabled before saving an empty list, and IPs must be restored manually before re-enabling.
- Local diagnostics do not establish that FreeKassa has sent a callback or that any order has actually been paid. Signature checks remain mandatory.

**FreeKassa stays TEST-ONLY in this build:** external admin sandbox checkout and live wallet settlement are deliberately unavailable until the next iterations. YooMoney is unaffected by this IP policy.

## FreeKassa administrator-only sandbox checkout (0.55.2 / Donations 1.17.2)

After configuring secrets, allowed sender IPs, currencies and enabling FreeKassa in **Test** mode, open **Donations → Payment providers → FreeKassa**. Owners and Administrators can start a dedicated test purchase (amount 1.00–100.00, selected enabled currency). The real external FreeKassa checkout opens; refresh the admin page to view signed callback status.

**Enable FreeKassa's test mode in the merchant dashboard FIRST.** KaevCMS cannot toggle the vendor's sandbox remotely or guarantee that a real payment will not be charged if the vendor is left in live mode. The vendor controls minimum amounts and test payment methods. Official docs: https://docs.freekassa.net/.

- These admin test references are durable, separate from player orders and never linked to a wallet or CMS player. No coins are credited on any test, even after provider mode changes or duplicate callbacks.
- Validation covers trusted callback sender IP, Secret 2 signature, amount, merchant, order UUID and provider `intid`. Acknowledgment `YES` follows durable verification; failed storage does not acknowledge.
- Both bounded URL-encoded and multipart form-data POST transports are supported with duplicate-field rejection. Set the public HTTPS callback URL shown in the admin panel.
- “Signed callback received” proves only notification verification, not vendor sandbox state, actual fund movement, or future live readiness.
- Finish in-flight tests before rotating the notification secret, or start another test.
- **Live FreeKassa player purchases remain blocked** until API currency/status verification and production acceptance. YooMoney is unaffected.


## FreeKassa live top-ups (KaevCMS 0.55.3 / Donations 1.17.3)

**QA candidate only — production verification pending.** Under **Donations → Payment providers → FreeKassa**, configure merchant ID, checkout secret 1, callback secret 2, and the **FreeKassa API key** (required for live). Choose supported currencies and set coin prices. Configure allowed callback sender IPs and trusted proxies, and set the displayed public HTTPS POST notification URL in the merchant dashboard. Turn off the vendor's sandbox before charging real funds.

Select **Live** for FreeKassa and enable the provider; the same admin page now exposes the separate shared Donations master switch. Turn it on only when ready; it affects both FreeKassa and YooMoney new checkouts, but never disables outstanding callbacks. Players can select FreeKassa or YooMoney by supported currency. Checkout is server-priced; return from FreeKassa is NOT proof. Valid callbacks additionally require a merchant-authenticated `POST https://api.fk.life/v1/orders` lookup with paid status `1`, exact `merchant_order_id`, exact provider operation (`intid` compared against `fk_order_id`), exact currency and exact gross amount, handled as integer minor units. `CUR_ID` is a payment method, not the currency.

- API outage/mismatch fails closed with HTTP 503 (never `YES` or Wallet credit). Ensure merchant callback retries are enabled. Lost-callback recovery is part of iteration 6.
- API HMAC uses a DB-serialized increasing nonce stored in encrypted provider settings. No `.env` toggle or new migration. Disabling new payments does not discard existing order preparations or verification credentials.
- Isolated admin tests are only allowed in **Test** mode and never credit Wallet. There is no IP reset button.
- The published documentation does not explicitly guarantee that SCI `intid` equals API `fk_order_id` for every merchant. A real merchant control payment must establish this mapping before production acceptance. The 0.55.4 handler uses the authenticated API canonical ID instead of assuming these values are equal. Full Laravel/browser/DB regression testing is still required before production.

Official API: https://docs.freekassa.net/.


## FreeKassa API recovery and callback key rotation (0.55.4 / Donations 1.17.4)

**QA candidate only; not certified for live production collections.** When a completed live payment receives no callback, an owner or administrator can open **Donations → Orders → Order details** and use **Verify with FreeKassa**. This action is limited to an existing unpaid live FreeKassa preparation and uses merchant-authenticated `POST /v1/orders` with the immutable CMS order UUID, not user-submitted amounts or wallet IDs. Exactly one order with paid status `1`, exact currency and amount must be returned. Verified evidence is saved in the encrypted payment ledger and settled through the ordinary transactional Wallet service. Duplicate checks and late notifications do not double-credit. Unavailable/ambiguous API results never authorize a credit.

FreeKassa SCI `intid` and API `fk_order_id` are separately named and their equality is not documented. The canonical financial identifier comes from the authenticated API row (`fk_order_id`) after verifying its `merchant_order_id`, exact total, currency and paid status. The SCI callback `intid` remains untrusted metadata for matching purposes.

Up to four previous callback Secret 2 keys can remain encrypted for at most 24 hours after rotation and are accepted only for live payment preparations preceding that rotation. New orders always use current credentials. After the grace window, use merchant API reconciliation for confirmed old payments when the API remains available.

Before going live, validate real vendor API responses, the merchant's notification settings and retries (`YES`), trusted callback IP/proxy chain, amount/currency, duplicate processing, and Laravel/MySQL/browser tests. No default-IP restore button is provided. Official provider documentation: https://docs.freekassa.net/.
# FreeKassa 0.55.5 compatibility fixes

Donations 1.17.5 requires CMS 0.55.5 or newer: multipart notification handling and checkout CSP permissions depend on core changes. Deploy the matching CMS release rather than replacing only the module directory on an older core.

POST notifications support URL-encoded and multipart forms. When PHP already parsed multipart, one canonical field map is used without merging query parameters. Repeated fields on this path use PHP's selected value consistently for both signature and payment verification. Request sizes are bounded; arrays and observable files are rejected. This multipart path requires a valid Content-Length. No additional PHP or environment settings are required.

Merchant API dispatch uses the existing database cache-lock table to preserve increasing nonce order across workers. Timeouts/API errors do not confirm payment; delivery retries and administrative reconciliation remain safe. These checks do not replace verification with an actual merchant account and its sandbox mode.
