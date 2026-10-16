# Donations C1 — KaevCMS 0.50.0

`Donations 1.5.0` establishes a provider-agnostic payment architecture. There is **no working payment driver**, no real checkout, callback, wallet credit or money movement in this version. The old B5 unpaid drafts, private history and administration journal remain intact.

## Payment providers

“Donations → Payment providers” shows only trusted drivers installed with CMS code. The list is empty in 0.50.0. UnitPay and other future drivers must be registered through the PHP allowlist in `DonationProviderRegistry`, never by a user-supplied PHP class or arbitrary database entry.

Each installed provider has independent enabled (default off), `test`/`live` mode, selected supported currencies and encrypted credentials. Credentials are protected by Laravel Crypt/APP_KEY, never rendered back into forms, returned to the view model, or included in routine audit metadata. Leaving a credential field blank preserves its current value. Corrupt ciphertext fails closed. Back up APP_KEY: losing this key makes saved credentials inaccessible.

Owner/Administrator may configure; Auditor may inspect redacted state only; Editor has no access. Settings and audit share one database transaction. Currency prices per **one coin** remain independently configurable in Donations. Provider compatibility does not restrict the owner's fiat currency catalogue. Future checkout requires driver support, merchant selection and an enabled CMS price; there is no automatic FX conversion.

## Provider contract

`Contracts/PaymentProvider.php` defines a code, currency support, test capability, credential schema, configuration check, checkout from the server-owned immutable `PaymentIntent` and signature verification of `ProviderCallback` before constructing `VerifiedPayment`. A browser return URL never proves payment. `PaymentCheckout` is a redirect only. Real drivers, authenticated callbacks, settled-order lifecycle, fee support and credit issuance are C2–C4 scope.

## Acceptance

Run `deployment/windows/quality.ps1`, `deployment/windows/browser-quality.ps1` and the `DonationProvidersTest` suite along with previous Donations/Wallet tests. Test fresh installation, migration over existing orders and provider-secret storage against a dedicated MySQL/MariaDB database. Ensure that no live checkout/webhook route is exposed and changes in provider settings cannot credit coins.

Do not disable a provider-enabled module with outstanding paid orders once real payment processing becomes available; durable webhook handling must be implemented and tested beforehand.
