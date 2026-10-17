# Donations 1.10.1 — C4d (KaevCMS 0.50.6)

YooMoney personal-wallet RUB driver (not YooKassa); supports multiple independently configured providers and currencies in the registry. C2 immutable preparations are unchanged. C3 adds a core-owned callback route independent of the Donations module UI, cryptographic HMAC-SHA256 verification, exact payer `withdraw_amount` snapshot comparison, durable encrypted signed receipts and atomic single-effect WalletGateway settlement (only if **both** trusted gates are expressly enabled). Receiver net `amount` is stored separately; it must never be used as the payer-debit amount. A duplicate operation ID is immutable; a second transfer for the same order is held for manual review.

**DO NOT USE FOR REAL PAYMENTS.** `config/donation-payments.php` ships with `callbacks_enabled=false` and `credits_enabled=false`; `config/wallet.php` keeps `enabled_sources.donations=false`. There is no public payment form/checkout or automatic production credit in this release. Local PHP tests simulating signed provider events are not a live transfer test.

Read-only lost-webhook recovery (no automatic credit from unsigned OAuth history):

```
php artisan kaevcms:donations-reconcile --scan --limit=30
php artisan kaevcms:donations-reconcile --scan --order=<order-uuid>
```

Only a token with `operation-history` scope can query YooMoney history. Reconciliation reports unverified candidates for manual administrative review, never credits them. `--retry-verified` can revalidate **encrypted signed** pending notifications, but the C3 release's credit gates stay closed. No periodic scanning is scheduled. All prior migrations remain unchanged; only an additive C3 migration is supplied. Verified payment receipts and settlement ledger cannot be rolled back while nonempty. See docs/ru/DONATIONS.md and docs/en/DONATIONS.md.

MySQL/MariaDB row-lock race gate on a **disposable** test database only: `APP_ENV=testing DONATIONS_TEST_ALLOW_CONCURRENCY=1 php tests/Integration/Donations/mysql-settlement-concurrency.php`. Checks ten concurrent deliveries of the same signed event and ten different signed events for the same order (one credit, all extra payments for review). Do not run on production.

## C4a — manual refund/dispute reporting, NOT automatic provider confirmation

Admin Donations → Orders → individual order: signed receipt gross (`withdraw_amount`), net (`amount`) and precise positive difference are displayed without floats. Owner/Admin can add manual *reported* dispute/refund/chargeback/note/resolution with a reason. The entry is immutable and audited, keyed by a unique request UUID, and tied to the selected non-test receipt. Refund/chargeback requires an already credited receipt. Auditor can view; Editor cannot access. The Orders filter can show only orders with manual reports.

This is a **human report**, never an authenticated refund/chargeback event from YooMoney. It does not change the order status or Wallet and does not initiate a transfer or correction. Investigate with the provider and use the established admin Wallet operations only after explicit review. Production callback, credit and checkout remain disabled. Migration 2026_10_17_020000 is additive and protects existing review evidence on rollback.

C4a does not complete the provider settlement/merchant acceptance gates. For a later C4b, add independently verified refund status, scheduled reconciliation and full live test only with explicit approval. Run Windows gates + clean and upgrade migration on disposable MySQL before acceptance.

## C4b — simpler delayed settlement (0.50.4)

New drafts expire 60 minutes after creation (existing deadlines are unchanged). This is the time for beginning a new payment, not a cutoff for delivery of signed notifications. A cryptographically authenticated matching YooMoney operation can settle from `draft`, `expired`, or `cancelled`, even when it arrives late or the provider has since been switched to test/disabled. The immutable prepared mode must have been `live`; provider test notifications and test preparations never credit. A second distinct payment for one settled order is flagged for review, not silently credited. Invalid signature, wrong currency or wrong `withdraw_amount` remain rejected/reviewed.

Player and staff UI distinguishes received/pending evidence from mismatched evidence, using short status labels. Exactly-once effect remains WalletGateway + immutable receipt + settlement uniqueness, with audit in one DB transaction. No new migrations, Wallet changes or production checkout: callback and credit gates remain disabled pending acceptance, integration testing and credential-rotation strategy.

## C4d — Reliable verified receipt retry (0.50.6)

Accepted YooMoney callbacks are HMAC-verified on receipt and persisted in an authenticated encrypted envelope with their verification snapshot. `kaevcms:donations-reconcile --retry-verified` can finish already **verified** pending receipts after key rotation/grace expiry, without treating unsigned history records as payment proof. The immutable order/payment/amount fingerprint is checked before settlement; malformed or swapped evidence never credits coins. Legacy encrypted raw callback receipts use the old, currently eligible signing-key verification path. No live checkout or credits are activated by this change.

## C4c — YooMoney HMAC rotation and focused recovery (0.50.5)

Updating the configured YooMoney `notification_secret` automatically retains the previous signing key inside the existing **encrypted provider settings**, for up to 24 hours. It is considered ONLY for live preparations created before the rotation. Newly prepared orders require the current secret, enforced by the maximum preparation ID recorded atomically at rotation (not timestamp precision). Earlier keys are not exposed to the UI, logs, API responses, or unrelated drivers; keys expire after 24 hours (maximum 4 retained). No migration or extra merchant setting is needed. **If a key was compromised, stop callback/credit processing** rather than relying on this ordinary-rotation grace, and investigate old orders; after the grace window use read-only recovery and manual review.

The `kaevcms:donations-reconcile --scan` command now checks the latest prepared orders first (up to `--limit`); use `--order=UUID` for a particular older order. Unsigned history records are only review candidates and cannot credit the Wallet. Callback/credit flags and real checkout are still OFF by default. Do not start live transfers before full tests.
