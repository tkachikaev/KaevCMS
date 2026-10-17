# Donations 1.7.0 — C3 (KaevCMS 0.50.2)

YooMoney personal-wallet RUB driver (not YooKassa); supports multiple independently configured providers and currencies in the registry. C2 immutable preparations are unchanged. C3 adds a core-owned callback route independent of the Donations module UI, cryptographic HMAC-SHA256 verification, exact payer `withdraw_amount` snapshot comparison, durable encrypted signed receipts and atomic single-effect WalletGateway settlement (only if **both** trusted gates are expressly enabled). Receiver net `amount` is stored separately; it must never be used as the payer-debit amount. A duplicate operation ID is immutable; a second transfer for the same order is held for manual review.

**DO NOT USE FOR REAL PAYMENTS.** `config/donation-payments.php` ships with `callbacks_enabled=false` and `credits_enabled=false`; `config/wallet.php` keeps `enabled_sources.donations=false`. There is no public payment form/checkout or automatic production credit in this release. Local PHP tests simulating signed provider events are not a live transfer test.

Read-only lost-webhook recovery (no automatic credit from unsigned OAuth history):

```
php artisan kaevcms:donations-reconcile --scan --limit=30
php artisan kaevcms:donations-reconcile --scan --order=<order-uuid>
```

Only a token with `operation-history` scope can query YooMoney history. Reconciliation reports unverified candidates for manual administrative review, never credits them. `--retry-verified` can revalidate **encrypted signed** pending notifications, but the C3 release's credit gates stay closed. No periodic scanning is scheduled. All prior migrations remain unchanged; only an additive C3 migration is supplied. Verified payment receipts and settlement ledger cannot be rolled back while nonempty. See docs/ru/DONATIONS.md and docs/en/DONATIONS.md.

MySQL/MariaDB row-lock race gate on a **disposable** test database only: `APP_ENV=testing DONATIONS_TEST_ALLOW_CONCURRENCY=1 php tests/Integration/Donations/mysql-settlement-concurrency.php`. Checks ten concurrent deliveries of the same signed event and ten different signed events for the same order (one credit, all extra payments for review). Do not run on production.
