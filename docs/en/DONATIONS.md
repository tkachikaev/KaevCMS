# Donations C5 — KaevCMS 0.50.7

**Current Donations 1.11.0**: 60-minute draft window, authenticated late settlement after local cancel/expiry or provider mode change, and simplified player statuses. C4a dispute reporting and exact payer/net reporting remain. Live checkout and wallet credits stay disabled. Earlier sections are historical.

## Historical C1 reference


`Donations 1.5.0` establishes a provider-agnostic payment architecture. There is **no working payment driver**, no real checkout, callback, wallet credit or money movement in this version. The old B5 unpaid drafts, private history and administration journal remain intact.

## Payment providers

“Donations → Payment providers” shows only trusted drivers installed with CMS code. The list is empty in 0.50.0. UnitPay and other future drivers must be registered through the PHP allowlist in `DonationProviderRegistry`, never by a user-supplied PHP class or arbitrary database entry.

Each installed provider has independent enabled (default off), `test`/`live` mode, selected supported currencies and encrypted credentials. Credentials are protected by Laravel Crypt/APP_KEY, never rendered back into forms, returned to the view model, or included in routine audit metadata. Leaving a credential field blank preserves its current value. Corrupt ciphertext fails closed. Back up APP_KEY: losing this key makes saved credentials inaccessible.

Owner/Administrator may configure; Auditor may inspect redacted state only; Editor has no access. Settings and audit share one database transaction. Currency prices per **one coin** remain independently configurable in Donations. Provider compatibility does not restrict the owner's fiat currency catalogue. Future checkout requires driver support, merchant selection and an enabled CMS price; there is no automatic FX conversion.

## Provider contract

`Contracts/PaymentProvider.php` defines a code, currency support, test capability, credential schema, configuration check, checkout from the server-owned immutable `PaymentIntent` and signature verification of `ProviderCallback` before constructing `VerifiedPayment`. A browser return URL never proves payment. `PaymentCheckout` is a redirect only. Real drivers, authenticated callbacks, settled-order lifecycle, fee support and credit issuance are C2–C4 scope.

## Acceptance

While top-ups are visible, the account menu shows **Add coins** and opens the coin quantity/currency selection directly. **My test orders** opens history; history and order details offer **Choose coin quantity** to return. When top-ups are hidden, the menu opens history and the new-request link is hidden while existing orders remain accessible. The module registers only one account navigation entry, so history cannot overwrite the calculator link.

The calculator uses a rounded card in both themes. Quantity input and slider share the configured limits; changing quantity or currency updates the total and price breakdown immediately without network requests. Browser arithmetic uses integer minor units (`BigInt`) and currency precision. Invalid quantities clear the previous total and disable draft submission. Draft creation submits the selected quantity/currency and the server rereads the rate and recalculates the amount. The calculator initializes again after Livewire navigation. Without JavaScript, **Calculate** remains available for server-side quotes.

Donations settings now use one form for player visibility, coin limits, currency prices and availability. **Save changes** persists the entire form in one transaction. There are no separate save buttons for individual currencies or general settings. Selecting and adding a currency only stages a row until the form is saved. The quote preview updates locally without submitting, including Enter in its quantity field.

**Remove currency** stages deletion of an existing rate and offers **Undo removal** until saving; removing a new unsaved row simply discards it. Saving removes the rate from future quotes/orders, including the last configured rate. Existing orders, events, immutable price/currency snapshots and original order-request retries remain intact. Provider credentials/settings are unaffected.

Validation or audit failure rolls back the entire form, including deletions, and preserves pending input/removal flags. A settings snapshot prevents stale tabs from overwriting another save, including legacy single-rate changes; reload on conflict. Previous individual save endpoints remain compatible, while the new UI uses `PUT /admin/extensions/donations/workspace` exclusively.

`DonationWorkspaceTest` covers atomic saves, roles, invalid-price rollback, audit rollback, removal with preserved order history, original-request retries and stale-tab conflicts. `node tests/browser/run.mjs donations.spec.mjs` exercises unified saves, add/remove/undo, invalid-price correction and Livewire reinitialization at 1440 px and 390 px in a disposable database.

Verified on 2026-10-16 without changing version 0.50.0: full `quality.ps1` passed 1,062 tests with 20,704 assertions and 11 expected Windows skips; full `browser-quality.ps1` passed all 75 browser tests.

Run `deployment/windows/quality.ps1`, `deployment/windows/browser-quality.ps1` and the `DonationProvidersTest` suite along with previous Donations/Wallet tests. Test fresh installation, migration over existing orders and provider-secret storage against a dedicated MySQL/MariaDB database. Ensure that no live checkout/webhook route is exposed and changes in provider settings cannot credit coins.

Do not disable a provider-enabled module with outstanding paid orders once real payment processing becomes available; durable webhook handling must be implemented and tested beforehand.

---

## C2 — 0.50.1 / Donations 1.6.0 (2026-10-17)

The first allowlisted integration is the **YooMoney personal-wallet P2P transfer** provider, not YooKassa. It supports **RUB only**; other CMS tariffs and independent provider settings remain unchanged. Admins may store an encrypted wallet receiver, HTTP notification signing secret, and optional **read-only** OAuth history token (`operation-history` scope, never `payment`). No notification URL is provided in this release because a live webhook handler has NOT been activated. Test mode is local preparation/provider test notifications, **not a money-transfer sandbox**.

A player may attach an enabled provider to their own, unexpired unpaid draft using **Prepare without paying**. This creates an immutable, audited preparation with an exact original order amount, currency, provider and mode. It does not execute or expose a payment POST form. Drafts remain cancelable, and Wallet/ledger/coin balances stay unchanged. The future POST DTO includes a fixed receiver and `sum` from server-side order snapshot, never the browser. No real-money URL is available to players in C2.

For notification verification, the provider uses `sign`: HMAC-SHA256 over all POST fields except `sign`, sorted alphabetically, values RFC3986 URL-encoded. SHA-1 authentication is obsolete. `withdraw_amount` is payer gross/debit; `amount` is receiver net after fees. A signed event is NOT sufficient settlement: C3 must match gross amount, operation, order, currency, recipient/mode and reconcile duplicates before any Wallet credit. Test notifications can never settle an order. `YooMoneyRecoveryProbe` offers a read-only lookup of candidate operation IDs by order `label` via `/api/operation-history`; it does not confirm final settlement or update any balance, and incomplete scans fail closed.

**Not payment-ready:** no live checkout, public webhook, paid/credited state transitions, automatic credit, module-disable-independent callback lifecycle, or refunds/chargebacks. These are C3/C4 acceptance requirements; never advertise this release as accepting actual payments. Previous migrations and core Wallet services remain untouched. Run `quality.ps1`, `browser-quality.ps1`, clean install/upgrade, and MySQL concurrency tests. Standalone signing and negative test (without Laravel dependencies): `php -d zend.assertions=1 -d assert.exception=1 tests/Integration/Donations/yoomoney-driver-standalone.php`.

Official references: https://yoomoney.ru/docs/payment-buttons/using-api/forms , https://yoomoney.ru/docs/payment-buttons/using-api/notifications , https://yoomoney.ru/docs/wallet/user-account/operation-history .


## C3 — 0.50.2 / Donations 1.7.0 (2026-10-17)

### Status: **real checkout and coin credits remain disabled**

- The player may only prepare an inert order. Both `config/donation-payments.php` switches (`callbacks_enabled`, `credits_enabled`) are `false`; `config/wallet.php` keeps the Donations credit source disabled. Do not enable any switch before C4 and full testing.
- Core-owned `POST /payments/yoomoney/notify` remains present when the Donations module UI is disabled. It responds HTTP 503 while the callback gate is closed. Future activation requires HTTPS, URL-encoded POST, signed `sign` HMAC-SHA256, correct order `label`, RUB currency code `643`, unique provider `operation_id`, and **exact** order gross payer `withdraw_amount`. Store receiver net `amount` separately; never compare it as payer debit.
- Verified original notifications are encrypted with Laravel Crypt and stored permanently. Only a matching live preparation/draft can become credited, under the **second** dedicated trusted credit switch **and** trusted core wallet source switch. The WalletGateway operation (`donation:<uuid>`), settlement, order state, and audit commit together or all roll back. Duplicate/invalid/test/cancelled/extra payments remain for admin review; pending paid evidence blocks player cancellation.
- A missing webhook may be investigated manually via `php artisan kaevcms:donations-reconcile --scan --limit=30` or `--order=UUID`. Only read-only OAuth scope `operation-history` is needed; unsigned history candidates are **not settlement proof** and cannot credit. `--retry-verified` decrypts and re-verifies previously signed notifications; default gates still prohibit credits. No automatic network job is scheduled.
- This is not a verified provider sandbox or production merchant deployment. Chargebacks, fees, key rotation, scheduled recovery and real end-to-end checkout remain for C4. Run Windows quality/browser gates, PHPUnit C3 cases, disposable MySQL/MariaDB concurrency, clean install and 0.50.1 upgrade before production decisions.

For real MySQL/MariaDB concurrency on a disposable **test** database: `APP_ENV=testing DONATIONS_TEST_ALLOW_CONCURRENCY=1 php tests/Integration/Donations/mysql-settlement-concurrency.php`. Never run on production.

Before installing Donations on MariaDB, check `SHOW GLOBAL VARIABLES LIKE 'explicit_defaults_for_timestamp';`. The published financial migrations require explicit timestamp defaults: configure `explicit_defaults_for_timestamp=ON` on the selected database server. MariaDB 10.4 with this setting OFF fails to create `module_donation_recovery_candidates` under strict SQL mode. Do not edit previously applied migrations or disable strict mode to work around this. An isolated QA container can set `--explicit-defaults-for-timestamp=ON` at startup without changing another local server.

Also run `tests/Integration/Donations/mysql-order-concurrency.php` for concurrent creation, cancellation and tariff snapshots, and `tests/Integration/Wallet/mysql-concurrency.php` with `WALLET_TEST_ALLOW_CONCURRENCY=1` for balance and ledger races. Each script must return exit code 0 **and** its final success message; uncaught exceptions return a failing exit code. After a CMS update, approve the pending Donations module update in **Modules** before checking its player pages. This preserves the module approval boundary.


## C4a — 0.50.3 / Donations 1.8.0 (2026-10-17)

Adds a persistent **administrator-reported** review journal. This is not YooMoney-verifiable evidence of a refund or chargeback. Owner/Admin may record a dispute, reported refund/chargeback, note and manual resolution tied to a specific non-test receipt, with required reason, stable idempotency UUID and fail-closed audit in the same DB transaction. Refund/chargeback reports require a credited receipt. Auditor may read, Editor cannot write. Orders may be filtered by presence of manual reports.

The signed YooMoney receipt displays exact `withdraw_amount` (payer gross), `amount` (merchant net) and their minor-unit difference. No floats or assumed provider fee rates. No Wallet debit, automated refund, new paid state or checkout is introduced. Previously released 71 migrations and core Wallet code remain unchanged; only additive migration `2026_10_17_020000` is new. The production callback and credit gates stay closed. Windows tests plus clean-install/0.50.2-upgrade on a disposable MySQL DB are required before accepting the version. Provider-verified refund/dispute and scheduled reconciliation remain deferred to C4b.

## C4b — 0.50.4 / Donations 1.9.0 (2026-10-17)

- New drafts expire after **60 minutes** (existing order deadlines stay unchanged). Expiry limits starting a payment, **never late delivery of a signed callback**.
- Verified matching YooMoney notifications for an immutable prior **live** preparation can be settled from `draft`, `expired`, or `cancelled`, including after provider disable or test/live switch. The gross `withdraw_amount` must exactly match the snapshotted amount, and the HMAC, currency, UUID and uniqueness checks remain strict. Prior test preparations can never credit.
- Pending authenticated receipts display **Processing**; mismatches display **Payment needs review**. A second payment for a credited order is reviewed rather than credited again. No Wallet changes or migrations.
- Callback, checkout and credits are **still inactive by default**; run Windows gates, isolated MySQL concurrency and install/upgrade from 0.50.3, and test provider notification cases before production enablement. Key rotation after live checkout needs a separate verified strategy before activation.

## C4d — 0.50.6 / Donations 1.10.1 (2026-10-17)

- A callback is first HMAC-verified, then its payment snapshot and original message are stored as **Laravel-Crypt authenticated evidence**. A previously verified pending payment can be retried after the retired YooMoney signing key has expired.
- Exact order, provider operation ID, payer `withdraw_amount`, receiver `amount`, currency and fingerprint still must match the original receipt. Corrupted/swapped evidence and unsigned history candidates cannot credit a wallet. A newly received callback signed with an expired key still fails verification.
- Legacy C3/C4 encrypted raw-only receipts can still be processed with a currently eligible signing key. No published financial migrations or Wallet Core behavior changed.
- Production checkout, callback and credits remain disabled pending Windows/Playwright/PHPUnit/MySQL full acceptance.

## C4c — 0.50.5 / Donations 1.10.0 (2026-10-17)

- When the YooMoney notification HMAC secret changes, the previously used key is retained within the **encrypted** existing provider settings for **24 hours**. It is eligible only for immutable live preparations made **before** rotation; newly prepared payments use the new key (atomic maximum-preparation-ID cutoff, even in the same second). At most four previous keys can be retained for consecutive rotations. No extra merchant controls, migrations or exposed secrets.
- This is a convenience for routine rotation, **not compromised-secret handling**. On suspected key theft, stop callbacks and credits and investigate existing orders. After the grace period, old signatures are not accepted; read-only recovery/manual review is needed.
- `php artisan kaevcms:donations-reconcile --scan` prioritizes the most recent preparations; use `--order=UUID` for a particular older order. Unsigned API history is never sufficient to credit Wallet.
- The 3 payment gates still default to false, and there is still no live checkout. Windows tests, full Laravel QA, isolated MySQL concurrency and clean/upgrade checks have not been run in this release environment.

## C5 — guarded YooMoney checkout page (0.50.7)

- The account owner may visit `/modules/donations/orders/{UUID}/checkout` once explicitly enabled: it renders official YooMoney `POST` forms for wallet (`PC`) and card (`AC`). The `sum` is the payer-debit RUB amount from the immutable CMS snapshot; `receiver` comes from trusted merchant configuration and `label=kaevcms-UUID`. Browser inputs and success redirects can never prove payment; verified HMAC callback, exact amount and idempotent Wallet credit are mandatory.
- **Live payments are OFF in this release**: `config/donation-payments.php` uses `checkout_enabled=false`, `callbacks_enabled=false`, `credits_enabled=false`; `config/wallet.php` has `enabled_sources.donations=false`. By default even a valid checkout URL yields 404 and no external form.
- Configure the HTTPS `/payments/yoomoney/notify` callback URL separately in merchant YooMoney wallet settings; the admin provider page displays the resolved callback URL. Do not expose HMAC secrets. Confirm the merchant's intended use is allowed under YooMoney wallet collection terms; it is not the same as YooKassa acquiring.
- Before enabling any gate: PHPUnit/PHPStan/Pint, both Windows quality suites, dedicated MySQL/MariaDB race tests, clean install/upgrade, signed callback replay/fee/recovery tests and separate approved small live transfer.
