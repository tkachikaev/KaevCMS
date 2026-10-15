# KaevCMS Core Wallet

Starting with KaevCMS 0.48.0 (iteration A1), the primary CMS database contains two new tables:

- `wallets`: at most one wallet per `users.id`, initially `0` coins, plus a reserved block flag (management arrives in A7). The foreign key prevents cascading wallet deletion when a user is deleted.
- `wallet_entries`: the future append-only transaction journal. The schema includes a globally unique operation UUID, a unique `source`/`source_key` pair, SHA-256 request fingerprint, direction, unsigned amount, before/after balances, reference/reason, actor, and timestamp. Ledger rows cannot be cascade-deleted with a wallet.

Existing users receive a zero wallet during the migration. The A2 atomic wallet engine creates wallets on demand for newly registered users. A missing row for a new user does not mean a nonzero balance or debt.

**A3 adds a trusted module-facing gateway.** The atomic engine remains internal and must never be exposed to user-controlled HTTP/Livewire parameters. Manual operations are currently API-only; wallet UI, provider integration, and fiat pricing are not enabled. `Wallet` and `WalletEntry` reject normal Eloquent mass assignment. That model guard is not a substitute for database permissions.

Coin balances use nonnegative integral units (`unsigned BIGINT`), never fiat currencies. Schema unsigned behavior varies by database (notably SQLite); A2 validates positive integral amounts, caps PHP integer ranges and uses transaction-safe row locks on MySQL/MariaDB.

**Rollback safety:** the migration refuses rollback if any ledger entries or nonzero wallet balances exist. Back up the CMS database before any schema rollback.

RUB/USD and other monetary tariffs, fees, and payment providers belong to the separate Donations module (stages B/C). This migration never accesses LoginServer/GameServer databases.

## A2 internal wallet service

`App\Services\Wallet\WalletTransactionService` exposes internal `credit(...)` and `debit(...)` operations. Each call must supply a persisted CMS user, a **positive integer** amount, trusted source name (`[a-z0-9._-]`, at most 64 characters), and stable source-specific key (max 120 ASCII characters, such as a payment/order UUID). Reference, reason and persisted User/Admin actor are optional for internal operations; changing them on retry is a conflict. A3 adds the policy-checked module gateway and transaction audit. **Never call the internal engine directly from a player request or treat its methods as public API.**

The service serializes operations using the user and wallet row locks; creates a missing wallet on demand; checks the latest journal balance against the current balance; rejects insufficient funds, negative/zero inputs, blocked spending, integer overflow, and key reuse with a different request fingerprint. Successful balance + ledger writes commit together. Credit is allowed while a wallet is blocked; only spending is disabled. A retry of an already successful operation returns the same ledger entry without making another balance change.

No schema migration changed in A2. The journal is **append-only by application contract**, not tamper-proof against privileged SQL writers. A3 provides the full read-only historical integrity check and module authorization boundary.

### Tests

- PHPUnit: `php artisan test --filter=WalletTransactionServiceTest` (normal test DB, usually SQLite).
- **Additional mandatory MySQL/MariaDB concurrency acceptance:** configure a **disposable** MySQL test database (name includes `test`), run migration in that database, and execute with `APP_ENV=testing` and `WALLET_TEST_ALLOW_CONCURRENCY=1`:
  `php tests/Integration/Wallet/mysql-concurrency.php`
  This spawns ten independent debit workers and ten identical credit workers and checks final balances and ledger entries. **Never run against a production database.** The isolated test environment must load matching DB credentials via `.env.testing` or equivalent.
- Windows `quality.ps1` and `browser-quality.ps1` still require real local execution; SQLite tests alone do not prove concurrency on MySQL/MariaDB.

## A3: official integration gateway and audit (0.48.2)

Modules should inject `App\Contracts\WalletGateway`, **not** `WalletTransactionService` or raw DB connections. `WalletSource` is a fixed server-side enum, not an HTTP field. `config/wallet.php` explicitly disables all module sources until trusted integration code enables the relevant source; A3 does not activate Donations, rewards or Shop coin flows. Future module integration must verify its own order/ownership/payment status before calling the gateway. Arbitrary installed PHP code is trusted by the application and cannot be sandboxed by a Laravel service.

- `creditFromModule(WalletSource $source, User $recipient, int $amount, string $key, string $reference)` supports donations, promo codes, daily rewards and shop refund sources **only if individually enabled**. The reference must be nonempty and source-specific; retry key and amount must be stable. No user-facing request may choose the source.
- `debitForOwner(User $owner, int $amount, string $key, string $reference)` requires the actual signed-in, active web user to match the supplied owner and Shop to be enabled. No guest or cross-account debit.
- `manualCredit`/`manualDebit` require a signed-in, active admin (Owner or Administrator) with `wallet.manage`, whose persisted identity matches the supplied actor, and a nonempty reason. `wallet.view` is reserved for A6 UI. Auditor has view only; Editor has neither. These are server APIs, **not** public routes; the admin UI is planned for A6.

On every *new* balance mutation, the existing CMS `AuditLogger` writes a `wallet` event inside the same transaction as the balance and ledger. If the audit insert fails, everything is rolled back (`audit_unavailable`); an exact idempotent replay creates no new audit event. Audit log retention may later remove these secondary events; permanent financial history is held in `wallet_entries`.

Read-only check: `php artisan kaevcms:wallets-reconcile` or `php artisan kaevcms:wallets-reconcile --user=123`; optionally use `--limit=100`. The command locks each wallet while verifying all sequential entries, reports invariant violations by wallet ID and returns a failure status on any mismatch. **It never updates a balance or fixes history.** Historical checks detect most accidental corruption; privileged direct SQL rewriting a whole history plus the balance is outside this contract.

Tests: `php artisan test --filter=Wallet` and `php artisan test --filter=AdminRoleTest`. Run standard Windows gates and isolated MySQL/MariaDB concurrency tests separately. No A1 migrations were rewritten.

## A4: configurable virtual currency appearance (0.48.3)

In the administrator panel go to **Settings → Wallet appearance**. Owner/Administrator may edit; Auditor may view; Editor and guests cannot access. Configure the RU/EN singular/few/many coin labels and an optional short symbol. Russian uses `one` for 1/21, `few` for 2/3/4 except 12–14, `many` for 0/5/11 etc. English uses singular for 1 and plural otherwise. The service `WalletAppearanceSettings` provides `values()`, `forms()` and `label()` to future A5 player views; this release does not change both account themes. Changes are display-only and do not touch existing wallet balances, financial entries or game databases.

Coin icon uploads reuse the standard `SettingsImageStorage` and `PublicImageStorage` under `public/uploads/settings/wallet-coin/<uuid>.png|webp`. Accepted images: PNG/WebP by actual MIME and matching extension, maximum 1 MiB and 1024 px in each dimension. SVG, JPG, ICO and PHP are disallowed. Paths are whitelisted, upload files use UUID names and old icons are deleted only after saving the replacement. If unset or removed, the admin preview uses the built-in coin symbol. Upload directories are user runtime and must never be packaged or deleted by updates; the parent uploads directory denies PHP execution on Apache. Any nginx configuration must separately disallow executing scripts from uploads. New settings use existing `cms_settings`; no migration or composer package is required.

No fiat exchange rate or Donations price is defined in Core Wallet. Price per coin will belong to the future Donations module. When running browser/Windows gates, also test the wallet appearance admin form, preview, uploads and standard/split layouts. `WalletAppearanceSettingsTest` covers malicious file extensions, MIME mismatches, roles and roundtrip settings.

## A5: player wallet (0.48.4)

Signed-in verified players can open **My wallet** at `/account/wallet` or `/{locale}/account/wallet`. Both bundled account themes provide a wallet link in the sidebar, live balance and custom coin icon in the topbar and profile menu, and a private, paginated 20-row transaction history. If a newly registered user has no wallet yet, the page displays zero without writing an artificial wallet record. A blocked wallet displays the restricted-spending notice. The page never allows credit, debit, transfers, payment initiation or configuration changes.

History queries are scoped using the authenticated CMS user relation only; URL parameters cannot select another wallet. Entries expose only credit/debit, amount, timestamp and balance after the operation. Reason, reference, source_key, UUID, fingerprint and private actor fields are intentionally hidden. There are no game-server database reads and no new migrations, dependencies, or edits to user uploads.

**Livewire header note:** the account sidebar remains persistent, but the account topbar was made nonpersistent so the balance and icon re-render on internal navigation (including return from wallet page). This changes the former topbar persistence contract; `AccountNavigationTest` now asserts the new live-value requirement, and `WalletPageTest` covers both themes. Check desktop and mobile widths and the page through `quality.ps1`, `browser-quality.ps1` during Windows acceptance.

Theme versions: `kaev-aurelia` 1.6.8 and `luxury` 1.6.6, both require CMS 0.48.4. Core Wallet is display-only at this stage; real-currency prices and provider fees will be defined by a future Donations module.

## A6: wallet administration (0.48.5)

The **Users → Wallets** administration section is visible to Owner, Administrator and Auditor (`wallet.view`). Browse CMS users by name, email or ID, view current balance, blocked state and private paginated journal; user detail pages link to wallets. Empty wallets display zero and are not created on GET. Wallet amounts and history always belong to the selected CMS user, never a GameServer account.

Owner and Administrator with `wallet.manage` can manually credit or debit coins. The server accepts only positive decimal **integer** quantities within PHP's signed integer range, one of `credit/debit`, an obligatory 3–190-character reason, an explicit confirmation checkbox and a per-form UUID idempotency key. Retries with the same key+content return the original entry instead of writing twice; reusing a key with different content is rejected. The existing `WalletGateway` performs a fresh admin permission check and invokes the atomic wallet engine. Insufficient balance, spending block, overflow and ledger mismatch fail safely. Audit rows and ledger changes are atomic. Auditor is strictly read-only; Editor is denied.

**Not yet available:** block/unblock management (A7), fiat price tables, payment initiation, refunds, Donations and Shop. No migration edits, new dependencies or theme changes. Verification includes Windows `quality.ps1`, `browser-quality.ps1` and independent MySQL concurrency tests; these were not run in the packaging environment.

## A7: wallet block/unblock and permanent history (0.48.6)

Owner and Administrator (`wallet.manage`) can block or unblock outgoing coin spending under **Users → Wallets → [user]**. An explicit confirmation, reason (3–190 characters), and unique form UUID are mandatory. The action is a state transition, not a financial entry: balances and `wallet_entries` never change. A blocked wallet **can receive credits** (e.g. payment settlement) but **cannot be debited** by administrator or future Shop. A no-op state change is rejected. Auditor can read status history, but not change status; Editor/guests have no access.

New `wallet_status_events` persists immutable status transitions, actor admin, reason, timestamp, unique idempotency key/fingerprint and before/after values. Unlike the secondary `audit_logs`, these status records are never removed by audit retention. The existing `AuditLogger` also records the change; both records and the wallet flag are committed atomically, otherwise the entire action rolls back. A retry with the original payload/key returns the prior event without changing state again; key reuse with different content/target is rejected. Each change serializes with the core wallet engine using the same user/wallet row lock order. Unblocking refuses a wallet that fails the full ledger integrity check; blocking remains possible to freeze a suspect balance.

The new `2026_10_15_000001_create_wallet_status_events_table.php` migration is additive, separate from the **unchanged** published A1 wallet migration. It does not modify user wallets during installation, GameServer databases, or any existing financial entries. It refuses rollback if status history exists. Do not bypass that safeguard; back up the database before migrating or rolling back.

**Acceptance before Donations (B1):** run `quality.ps1`, `browser-quality.ps1`, the wallet PHPUnit suite, wallet reconciliation CLI, isolated MySQL concurrency tests, clean installation and direct/cumulative upgrade scenarios. Verify blocked spending vs incoming credits, repeated status POST, concurrent block/debit sequencing, actual audit rollback, mobile UI in both account themes, and runtime protection. These gates have not been reported as passed by the user yet.
