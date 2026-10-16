# Donations C1 — KaevCMS 0.50.0

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
