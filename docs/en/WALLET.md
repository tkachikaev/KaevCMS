# Core Wallet

The wallet stores a coin balance for every CMS account (`users.id`), not for a game account. Coins are whole non-negative numbers; the wallet never stores roubles, dollars or other money. Coin prices, payment providers and fees belong to the [Donations](DONATIONS.md) module. The wallet never reads or changes LoginServer/GameServer databases.

## How the data is stored

The primary CMS database contains three wallet tables:

- `wallets` — at most one wallet per CMS user, starting at `0` coins, plus the spending-block flag. Existing users received a zero wallet when the table was created; new users get a wallet on their first operation. A missing row means a zero balance, not a debt.
- `wallet_entries` — the permanent transaction journal. Every credit or debit records a unique operation UUID, a unique `source`/`source_key` pair, a request fingerprint, direction, amount, balance before and after, reference or reason, actor, and time.
- `wallet_status_events` — the permanent history of spending blocks and unblocks: actor, reason, time, and the state before and after.

The journal is append-only by application rules; a person with direct SQL access could still change it, so protect the database. Balance, journal entry, and audit record are saved in one transaction: if any part fails, nothing changes. Repeating the same operation with the same key returns the original entry and does not add coins twice; reusing a key with different content is rejected. The general audit log may be cleaned by its retention setting, but `wallet_entries` and `wallet_status_events` are never removed automatically.

The wallet migrations refuse to roll back while journal entries, non-zero balances, or status history exist. Back up the CMS database before any schema rollback.

## Where coins come from

Only trusted server code can change a balance. The sources enabled in `config/wallet.php` are:

- **Donations** — coins credited after a verified YooMoney payment;
- **Donations exchange** — coins a player spends to receive a game item in the Web Inventory;
- **manual** — credits and debits made by an administrator (see below).

Promo Codes, Daily Rewards, and Shop sources exist but are disabled.

## Administration

**Users → Wallets** is available to Owner, Administrator, and Auditor. Search by name, email, or ID to see the current balance, the block state, the private journal, and the block history. User detail pages link to the wallet. Viewing an empty wallet shows zero and does not create a record. Auditor is read-only; Editor has no access.

Owner and Administrator can:

- **Credit or debit coins manually.** Enter a positive whole number, a reason (3–190 characters), and tick the confirmation. Insufficient balance or a damaged journal is rejected safely. A manual adjustment is not a refund through the payment provider.
- **Block or unblock spending.** A reason (3–190 characters) and confirmation are required. A block changes no balance and no journal entry. A blocked wallet still receives credits (for example, a paid top-up), but the player cannot spend coins. An administrator can still make a manual debit with a reason. Unblocking is refused if the journal fails the integrity check; blocking is always possible to freeze a suspicious balance.

Each administrator has separate limits: six adjustments and six status changes per minute.

**Settings → Wallet appearance** sets the coin name in Russian and English (with the correct plural forms), an optional short symbol, and an optional icon. The icon must be a real PNG or WebP file up to 1 MiB and 1024 px on each side. It is stored under `public/uploads/settings/wallet-coin/`; without it, the bundled `public/assets/images/wallet-coin.webp` coin is shown. Appearance changes never affect balances.

## Player wallet

Signed-in players with a verified email open **My wallet** at `/account/wallet`. The bundled account themes show the balance and coin icon in the top bar and account menu. A blocked wallet shows a notice that spending is restricted. Players cannot credit, debit, or transfer coins on this page.

The page has one paginated history: credits, debits, and unpaid Donations top-ups. A purchase is shown once; the journal is the authority for received coins. Pending top-ups come first, with payment and cancellation actions and an expiry timer. Cancelled and expired attempts are hidden by default and shown with **Show inactive top-ups**. Players see only the direction, amount, date, and balance after each operation; internal keys, references, and administrator details stay hidden. The old `/modules/donations/orders` address redirects to this history.

## Checking the journal

```bash
php artisan kaevcms:wallets-reconcile
php artisan kaevcms:wallets-reconcile --user=123
php artisan kaevcms:wallets-reconcile --limit=100
```

The command checks every journal entry against the stored balance and returns an error status listing wallet IDs on any mismatch. It is read-only: it never changes a balance or fixes history.

## For module developers

Modules use `App\Contracts\WalletGateway`, never `WalletTransactionService` or direct SQL. The source is the server-side `WalletSource` enum, never an HTTP field, and it must be enabled in `config/wallet.php`. The module must verify its own order, ownership, and payment state before calling the gateway:

- `creditFromModule(...)` — credits from an enabled module source with a stable key and a non-empty reference;
- `debitForOwner(...)` — spending by the signed-in, active owner of the wallet;
- `manualCredit(...)` / `manualDebit(...)` — administrator operations that require the `wallet.manage` permission and a reason.

Tests: `php artisan test --filter=Wallet`. The MySQL/MariaDB concurrency check `php tests/Integration/Wallet/mysql-concurrency.php` must be run only against a disposable test database (its name must contain `test`) with `APP_ENV=testing` and `WALLET_TEST_ALLOW_CONCURRENCY=1`. Never run it against a production database.
