# Changelog

## 0.55.12 - 2026-10-27

**Donations 1.17.12 / PayPal Orders v2 QA fixes.** Real PayPal Sandbox and a separately approved Live control payment remain release acceptance gates.

- Unify payment provider cards with local FreeKassa/PayPal brand icons, concise RU/EN descriptions and aligned controls on desktop and mobile.
- Validate the expected PayPal merchant before Capture; preserve verified progress when a concurrent webhook finishes before the Capture response.
- Complete the PayPal attempt status atomically with its Wallet settlement, including retained-receipt recovery. Verified refund/dispute notices use the exact credited receipt and remain informational only.
- Keep manual reconciliation results that require review distinct from pending payments. Expired or abandoned Create claims require review without a new request ID; unexpected HTTP redirects remain uncertain outcomes.
- Preserve applied migration files byte-for-byte; two historical files are excluded from automatic formatting to retain their lifecycle checksums.
- Repair release metadata, updater file inventory, localized Sandbox expectations and financial integration test isolation. Add concurrent Capture/webhook/recovery regression coverage. Framework dependencies and Wallet Core are unchanged.

## 0.55.11 - 2026-10-27

**Donations 1.17.11 / PayPal Orders v2 iteration 6 — QA candidate; not production-certified.** Full source based on 0.55.10; PHP/Laravel/Livewire/Composer dependencies, Wallet and previously published migrations unchanged.

- Added complete bilingual PayPal Orders v2 setup and operations guides (`docs/ru/PAYPAL.md`, `docs/en/PAYPAL.md`): independently configured Sandbox/Live credentials, webhook ID and events, supported currencies, admin-only Sandbox purchase, Live readiness, read-only reconciliation, informational refunds/disputes and merchant policy responsibility. Linked these guides from Donations, Modules and documentation indexes and marked older phased documentation as historical.
- Improved PayPal webhook event-inbox processing order: the notification step is attempted before marking a verified, settled refund/dispute event processed. In a crash between these steps, a redelivery can safely retry with the existing `AdminNotificationCenter::notifyOnce()` deduplication. Refund/dispute events never debit Wallet or modify settled orders.
- Added a no-vendor negative standalone test for raw JSON-object requirements and refund capture-ID URL parsing (HTTPS PayPal host, no URL credentials/query/fragment/port, unique capture identity). Existing YooMoney/FreeKassa/PayPal standalone suites run without composer vendor.
- **Release acceptance pending:** complete PHPUnit/Feature/MySQL concurrency, PHPStan/Pint, Windows quality/browser-quality/Playwright, upgrade/fresh migrations and actual PayPal Sandbox+approved Live control payment. Production readiness is not asserted and PayPal should remain disabled until these steps pass.

## 0.55.10 - 2026-10-27

**Donations 1.17.10 / PayPal Orders v2 iteration 5 — QA candidate, not production certified.** Based on full 0.55.9 source, with unchanged Wallet, other payment providers, existing migration files and Composer dependencies.

- Added administrator-only, CSRF-protected, rate-limited **Check with PayPal API** on known Live order attempts. Only remote GET order and GET capture are used, with immutable invoice, gross, currency, merchant and capture validation; shared settlement is invoked only when completed proof is established. No blind Capture POST, including after timeouts or prior unknown outcomes.
- Unknown/ambiguous Capture and remote API/key rotation failures preserve attempt IDs and a sanitized diagnostic, with `requires_review`. Previously settled attempts cannot be downgraded.
- Refund, reversal and dispute webhook events remain signed/durable and now require a previously credited Live receipt bound to the exact original capture before appearing in the order. They never debit Wallet, undo credit, block players or change the paid order status.
- Added an additive migration only for minimal PayPal event resource ID and timestamp (same webhook event inbox, no dispute subsystem). Show PayPal notices/history and API verification in the existing admin order card; one deduplicated Owner/Administrator notification per event type + PayPal resource ID, linked to the order.
- Added RU/EN text and negative mocked Feature tests (forgery, wrong capture, duplicates, manual recovery, roles) and no-vendor source checks. PHPUnit/MySQL, browser testing and real PayPal Sandbox/Live confirmation remain necessary for acceptance.

## 0.55.9 - 2026-10-27

**Donations 1.17.9 / PayPal Orders v2 iteration 4: QA candidate; Live gateway is disabled by default and NOT yet production-certified.** Full source based on 0.55.8; existing Wallet, previous migrations, PHP framework and Composer dependencies unchanged.

- Registered a dedicated PayPal Orders v2 deferred-checkout driver with an isolated Live configuration; merchant account ID and separate Sandbox/Live webhook IDs are required for enabling player Live checkout. Dedicated settings UI only; the generic provider editor cannot replace the protected PayPal config.
- Created immutable Live attempts with expected merchant ID, a stable Create/Capture request pair and distinct invoice ID. REST Create/Capture happens outside SQL transactions. Real player redirect return is authenticated and always rechecks PayPal API before attempting Capture.
- Replaced the inert core PayPal webhook with HTTPS-only raw JSON endpoint (64 KiB/413) and PayPal postback signature verification using the **original JSON bytes** in `webhook_event`. Failed verification never acknowledges a payment. Added a minimal encrypted, deduplicated webhook-event inbox; no refunds or disputes act on Wallet.
- Verified completed PayPal orders and their canonical capture via server-side REST API before settlement. Order ID, invoice, gross, currency, one completed capture and **merchant payee ID** must all match the immutable CMS attempt. The existing Donations/Wallet transactional idempotency prevents double credit across webhook, return and retry.
- Kept provider deactivation separate from verification of previously created orders; Sandbox remains entirely disconnected from player orders and Wallet. Unknown Capture results forbid blind POST retries. PayPal is restricted to global full Live mode, not the legacy single-account pilot.
- Added mocked Feature tests for redirect, forged webhook, duplicate delivery, wrong merchant and Wallet credit; full Laravel/MySQL/Playwright and real Sandbox/Live checks still required in Codex. Iteration 5 handles read-only reconciliation UI and dispute/refund information.


## 0.55.8 - 2026-10-27

**Donations 1.17.8 / PayPal Orders v2 iteration 3: QA candidate; PayPal Live/player checkout is NOT enabled.** Full source based on 0.55.7. Wallet, Composer lock, framework, YooMoney/FreeKassa live drivers and already published migrations unchanged.

- Added PayPal settings card and a dedicated bilingual RU/EN administrator page with Sandbox/Live Client ID, encrypted Client Secret and Webhook ID, non-disclosing saved-secret hints, supported currency selection (including JPY zero decimals; incompatible HUF/TWD excluded) and read-only callback URL. PayPal configuration uses the existing encrypted provider-settings table.
- PayPal stays outside the player provider registry, with its `enabled` flag forced OFF in this iteration, regardless of saved Live credentials. No player-visible PayPal checkout, DonationOrder, Wallet credit or settlement is introduced.
- Added a sanitized diagnostic that verifies Sandbox or Live OAuth2 credentials using a fresh remote token request; this does not claim webhook registration, merchant availability or live-payment readiness.
- Added an administrator-only Sandbox purchase via the existing PayPal attempts table (one additive admin actor column): short SQL reservation, hosted redirect, server-confirmed APPROVED, explicit POST Capture, stable request IDs and review-only handling of uncertain outcomes. Sandbox success uses `sandbox_verified`, never a Live settlement-eligible status.
- Reserved a Core-owned PayPal webhook route, but it rejects **every** notification with HTTP 503 until signature verification is implemented in iteration 4 (64 KiB is rejected with 413); no events are accepted as proof.
- Added access-control and mocked HTTP Feature tests, no-vendor contract tests, admin CSP restriction to the Sandbox approval host and existing provider regression checks. PHPUnit/MySQL/PHPStan/Pint/Playwright and real Sandbox credentials are unavailable here; full acceptance remains Codex QA.


## 0.55.7 - 2026-10-27

**Donations 1.17.7 / PayPal Orders v2 iteration 2: QA candidate; PayPal is NOT enabled for players or administrators.** Full source built on 0.55.6. The live YooMoney/FreeKassa drivers, Wallet code, existing published migrations, Composer lock and framework versions remain unchanged.

- Added an isolated PayPal Orders v2 HTTP client: OAuth2 client credentials with encrypted cached access tokens scoped to Sandbox/Live and credential rotation, server-side Create/Show/Capture/Get Capture, bounded response parsing, HTTPS-only fixed API endpoints, no redirects and separate immutable PayPal-Request-Id values for Create and Capture.
- Added an idempotent local operations service that reserves and persists the first PayPal order ID and safe approval redirect outside SQL transactions. A repeated Create uses the same ID within the bounded window. APPROVED is established only with authenticated GET, never a browser return flag.
- Capture claims remain exclusive; known remote results are stored as pending/denied/completed-unsettled **without Wallet credit**. A timeout or malformed response becomes capture_unknown, blocks further POST and can only be inspected by GET in this iteration.
- Added strict remote URL domain/environment/order-token validation, immutable invoice/gross/currency comparison and PayPal decimal precision checks. Non-2xx errors/transport failures are classified without recording secrets or speculative successful payments.
- Additive migration saves the trusted PayPal approval URL; test-only Sandbox attempt remains separate from all CMS users and wallets. No PayPal driver, credentials admin UI, webhook endpoint, real Sandbox run or player checkout are enabled (iterations 3–5).
- Added mocked HTTP Feature scenarios and standalone pure-PHP parser checks. Full PHPUnit/MySQL/PHPStan/Pint/Playwright and merchant Sandbox tests remain for Codex/iteration 6. This ZIP is a source QA candidate, not a production-certified release.

## 0.55.6 - 2026-10-27

**Donations 1.17.6 / PayPal Orders v2 iteration 1 (1A + 1B): QA candidate; NOT production-ready for PayPal.** Full source from 0.55.5; Wallet, Composer lock, installed YooMoney/FreeKassa and published migrations unchanged.

- Added an opt-in deferred checkout interface: immutable local order/pricing preparation stays in a short SQL transaction; server-created REST checkout is invoked only after SQL commit. Read-only order pages cannot launch remote Create Order calls. Existing static YooMoney/FreeKassa checkout remains unchanged.
- Added one PayPal attempts table with frozen live order terms, merchant/environment identity, separate stable Create/Capture request UUIDs, unique invoice UUID, remote-ID uniqueness per merchant/environment, and SQL-serialized Create/Capture claims. Capture-unknown states forbid automatic POST retries; uncertain Create retries reuse the same request ID inside a bounded window.
- Added an additive receipts migration: optional net/fee and their explicit currencies, nullable received amount and LONGTEXT encrypted evidence for eventual bounded 64 KiB webhook bodies. Existing receipts are not rewritten; gross always derives from immutable original payer amount. Cross-currency fees are never subtracted in another currency.
- Preserved old encrypted signed provider receipt formats and hash fingerprints for YooMoney/FreeKassa; added v3 metadata only when explicitly provided. Admin order receipt display distinguishes unknown net/fee from zero. Existing FreeKassa commission remains unknown. Dashboard turnover and latest five payments now use verified payer gross, never nullable net.
- Added local reservation and deferred checkout Feature tests and no-vendor standalone receipt compatibility checks. PayPal driver, credentials UI, API, webhooks and Wallet settlement are NOT enabled by this release; they belong to iterations 2–5. Full PHPUnit/MySQL concurrency/PHPStan/Pint/Playwright and production acceptance are still required.

## 0.55.5 - 2026-10-27

**Donations 1.17.5 QA candidate. Not production-certified.** Updates from 0.55.4. Wallet, dependency lockfiles and published migrations are unchanged.

- Fixed FreeKassa multipart callbacks on normal PHP hosting, where PHP consumes the raw request body. A bounded, scalar-only canonical POST map is used consistently when raw multipart is unavailable; signed fields bypass string normalization on this callback only. Raw malformed bodies and mixed query inputs remain rejected.
- Allowed the fixed FreeKassa checkout origin in the player checkout CSP and the two administrator test-purchase pages; other administration pages retain self-only form actions. Removed an inline callback-address handler blocked by CSP.
- Serialized merchant API request dispatch, not only nonce allocation, using the existing database cache-lock table. Parallel confirmations preserve FreeKassa's increasing nonce contract without holding a wallet transaction during network I/O.
- Fixed saving FreeKassa settings with an empty proxy or sender list after Laravel normalizes blank form controls; empty lists remain deny-by-default.
- Made checkout text provider-neutral in RU/EN. Donations now requires CMS 0.55.5 because FreeKassa relies on its callback middleware and checkout security headers.
- Fixed incompatible PHPUnit helper method names, PHPStan type/control-flow issues and formatting. Added parsed multipart, exact CSP scope, browser redirect and isolated SQL concurrency coverage without submitting external payments.

## Unreleased

- Fixed HTTP 500 on the mail delivery settings page after Scheduler had recorded a heartbeat: corrected the Carbon class namespace in the last-run timestamp display.

## 0.55.4 - 2026-10-27

**Donations iteration 6 QA candidate — Donations 1.17.4. Not production-certified.** Full build from 0.55.3, with unchanged Wallet, Composer lockfile, and published migrations.

- Removed the undocumented assumption that FreeKassa SCI callback `intid` equals merchant API `fk_order_id`. The canonical provider payment ID now comes only from an authenticated, exact-match `POST /v1/orders` query by the immutable CMS merchant order UUID, requiring one paid row, correct amount, currency and status. The callback's unsigned `intid` is validated and kept in original encrypted evidence, not used as financial identity. Fail-closed handling remains for missing, ambiguous or inconsistent API responses.
- Added an explicit owner/administrator-only, CSRF-protected and rate-limited **Verify with FreeKassa API** action on unpaid FreeKassa LIVE orders. It can recover a missing callback using only merchant-authenticated API evidence and the existing immutable order snapshot, not arbitrary browser-supplied data. Recovery reuses the transactional Wallet settlement/receipt uniqueness and encrypted v2 evidence so late callbacks and repeats do not double-credit. API failures leave orders unpaid.
- Added a bounded 24-hour encrypted grace list (at most four entries) for rotated FreeKassa callback Secret 2 keys. A retired key is valid only for payment preparations whose IDs predate rotation. New checkouts use current keys. Key rotation does not bypass remote API proof.
- FreeKassa receipts show **gross** confirmed payment amount, while net settlement/fee are marked **unknown** in the order UI; the SCI commission field is not covered by the payment signature.
- Added RU/EN admin texts and implementation documentation; new API parser and feature regression scenarios include mismatched callback/API IDs, lost callbacks, forbidden admin roles, replay and delayed old-secret notifications. Old YooMoney compatibility is unchanged.
- Pure PHP FreeKassa and YooMoney driver checks, PHP lint, dependency guard, release metadata and archive integrity were checked. Full Laravel/PHPUnit, Pint, PHPStan, Playwright and real FreeKassa checkout were not run in this environment (missing vendor/runtime/merchant credentials). **Live receipt behavior remains unverified with a real merchant — do not open production payments yet.**

## 0.55.3 - 2026-10-27

**QA candidate, Donations iteration 5. Not production-certified.** Donations 1.17.3. Full release based on 0.55.2; Wallet/Composer/published migrations unchanged.

- Enabled FreeKassa live-mode configuration only with a merchant API key and existing verified SCI credentials. The admin-only test mode and separate no-Wallet test log remain unchanged. Independent enable/disable and provider currency selection work alongside YooMoney.
- Added authenticated `POST https://api.fk.life/v1/orders` lookup before crediting any real FreeKassa callback. HMAC-SHA256 signs sorted API fields; DB-serialized increasing nonces are stored encrypted with provider settings (no new schema). A remote PAID status (1), matching immutable merchant order UUID, FreeKassa operation `intid` vs API `fk_order_id`, exact currency and exact gross amount in integer minor units are all mandatory. No unchecked provider status or unsigned callback currency ever credits Wallet.
- Updated provider callback verification to distinguish TEST and LIVE based on the immutable preparation mode. API errors/missing results fail closed with HTTP 503, no `YES` acknowledgment, no receipt and no wallet credit. Verified live receipts still use the existing common transactional/idempotent Wallet settlement and v2 authenticated evidence.
- Corrected shared player checkout Blade to render provider-supplied GET/POST fields rather than only YooMoney POST inputs and the RUB currency label. Player provider selection and immutable order snapshots reuse existing Donations services.
- Added FreeKassa admin mode selector, shared payment-master activation controls (clearly labelled as global to all providers), and RU/EN text explaining API-key and merchant-side test-mode requirements. Updated reference docs; added pure-PHP API parser tests and Laravel Feature tests (written, not yet run). Fixed a preexisting standalone YooMoney test that relied on assignment inside disabled PHP `assert()`.
- **Important:** live merchant verification has NOT been executed; the public API may differ for a particular merchant, especially the relationship between SCI `intid` and API `fk_order_id`. If they differ, the integration fails closed rather than issuing an unverified credit. Lost callback recovery, rotation resilience, full PHPUnit/Playwright/MySQL, and production acceptance remain for iteration 6.

## 0.55.2 - 2026-10-27

**QA candidate, Donations iteration 4. Not production-certified.** Donations 1.17.2. Full release based on 0.55.1. No Composer or Wallet modifications, no published migrations altered.

- Added administrator-only FreeKassa external test checkout, with a merchant-side test-mode warning, amount 1.00–100.00 (minor units), selected currency and an existing signed SCI GET checkout. The merchant's test setting must be enabled manually; KaevCMS cannot turn it on remotely. **No automatic confirmation that the merchant sandbox is enabled.**
- Added a small additive `module_donation_freekassa_admin_tests` table to retain admin test references, immutable amounts, currencies, signed callback verification and provider operation IDs. This separate table is necessary because player donation orders require a real player user. No dummy player or Wallet records are ever created.
- Added callback dispatch to isolated admin test verification before normal donation settlement, independent of later provider enable/disable. Signed duplicate callbacks are acknowledged once durable; conflicting identifiers are rejected. Test transactions never touch player coin balances, and changing provider mode cannot reclassify them.
- Added support for strict `application/x-www-form-urlencoded` and `multipart/form-data` notification transports, with bounded body size and rejection of duplicated fields before cryptographic verification; existing IP allowlist validation remains mandatory.
- Prevented FreeKassa test mode from appearing in player payment methods or being selected through the player payment-preparation endpoint. Normal YooMoney payment mode remains unchanged.
- Added administrative test history and statuses, new RU/EN text and driver/transport/Feature test coverage. Test-only configuration no longer requires a player coin rate; real payments still require it.
- **Live FreeKassa is still disabled** by the driver's explicit guard. API currency/status reconciliation, live player checkout and recovery follow in iterations 5–6. Full PHPUnit, Laravel, browser and MySQL regression suites remain deferred for Codex, per project instruction; syntax and standalone smoke checks only.

## 0.55.1 - 2026-10-27

**QA candidate, Donations iteration 3. Not production-certified.** Donations 1.17.1. Based on the full 0.55.0 archive. No Composer, Wallet, schema or published migration changes.

- Added an audited FreeKassa-only callback sender IP allowlist editor in the Donations admin. Initially shown addresses match FreeKassa's official SCI notification IP list at release time. After the first save, explicitly configured addresses remain authoritative, including intentional deletions. No reset-to-default button, disabling the IP check, or implicit fallback after user customization.
- Added an independent optional trusted reverse-proxy IPv4/IPv6/CIDR list. Source-IP resolution checks the actual TCP peer (`REMOTE_ADDR`) and reads `X-Forwarded-For` only from explicitly trusted immediate proxies, using the rightmost untrusted hop. Arbitrary or malformed forwarded headers cannot whitelist an unknown sender. This callback-specific list works independently of unrelated framework proxy configuration.
- Both lists are validated, limited and stored in the provider's existing Laravel-encrypted settings document with admin authorization and fail-closed audit. No new tables or migrations. A blank IP list rejects new callback traffic and cannot be used with FreeKassa enabled. The original YooMoney settings and callbacks are unchanged.
- Added FreeKassa security UI and local diagnostics in Russian and English. The notification URL is selectable; warnings link to the official vendor documentation and explain external merchant sandbox activation, moderation and HTTPS limitations. Secrets are never rendered back to the browser.
- Added isolated IP-chain smoke coverage and Feature tests for editing, persistence, malformed IPs, permissions and spoofing. Updated the existing allowlist assertion for both bundled payment drivers.
- **Still TEST-ONLY:** external admin test checkout and real settlement remain disabled. Test checkout belongs to iteration 4; authoritative API currency/status reconciliation is required before live settlement.
- PHP syntax and stand-alone driver/IP tests were checked locally. PHPUnit, Pint, PHPStan and browser/regression suites are intentionally deferred for Codex review per user preference.

## 0.55.0 - 2026-10-27

**QA candidate, Donations iteration 2. Not production-certified.** Donations module 1.17.0. Based on the complete 0.54.5 archive. No changes to Composer dependencies, database schema or Wallet.

- Bundled the independently registered FreeKassa SCI driver with strict signed checkout construction and separate signing secrets for the payment form and merchant callback. The original YooMoney provider remains unchanged.
- FreeKassa signed callback parser rejects duplicate and array parameters, unsigned query data, malformed amounts/IDs, modified signatures, wrong merchant identities and unsafe checkout destinations. MD5 is computed using the original signed AMOUNT string while money is compared as integer minor units (e.g. 100, 100.0, 100.00).
- Added stable core-owned `/payments/freekassa/notify` POST endpoint, independent of the Donations UI toggle, with HTTPS, POST form encoding and CSRF exception matching the public provider callback model. The endpoint returns precisely `YES` after durable processing. The existing unique `(provider_code, provider_payment_id)` index covers FreeKassa `intid` replay detection.
- **FreeKassa is intentionally TEST-ONLY in 0.55.0.** The driver refuses `live` configuration; test notification evidence always stays non-crediting. External test checkout in the admin panel, IP allowlist editor, trusted proxy handling and server-side confirmation of provider currency/status are deferred. A new real payment cannot start in this build.
- Added standalone smoke coverage for checkout signatures, signed amount normalization, tampering, duplicate fields and live gating. No real merchant credentials used and no external payment API called.
- FreeKassa has no reset-to-default-IP control. Full PHPUnit, browser and MySQL concurrency tests are deferred to end-to-end Codex validation at the user's request.

## 0.54.5 - 2026-10-27

**QA candidate, iteration 1 of the Donations multi-provider work. Not production-certified.** Based on 0.54.4. No payment provider added yet: YooMoney remains the only bundled driver. No changes to Wallet, database schema, composer.json or composer.lock.

- Added an opt-in `RoutablePaymentProvider` contract for the trusted callback reference, provider-owned checkout validation and hard-coded callback route. Unreviewed `PaymentProvider` fixtures remain configurable but cannot be used for player payments.
- Separated shared payment preparations, provider checkout and settlement from YooMoney-specific references; existing YooMoney checkout remains available under its original class name and the public `/payments/yoomoney/notify` route is unchanged.
- Signature failures use a typed exception, so previous YooMoney keys are retried only when the signature itself is invalid, not for corrupted body, bad amount or unknown order.
- Stored signed YooMoney v1 receipt evidence remains compatible, including deferred credits after credential rotation. Added an authenticated provider-aware v2 receipt envelope, bound to the immutable preparation and receipt identity.
- Generalized readiness to any explicitly installed reviewed live driver while preserving the audited off/pilot/live switch currently used by YooMoney.
- Added second-driver (test fixture) feature tests for independent receipts, replay protection, test-mode non-credit, and pending receipt recovery after signing-key rotation. Expanded standalone YooMoney HMAC/evidence tests.
- Deferred until later iterations: FreeKassa API, IP allowlist UI, test checkout, FreeKassa callback endpoint, production activation and public documentation changes. No reset-to-default-IP button will be introduced.

## 0.54.4 - 2026-10-26

Updates from 0.54.3. No dependency updates, database migrations or payment changes. Universal drivers remain experimental.

- Fresh installations using the bundled environment template select Aurelia for the public website and Classic for player accounts. Existing environment files and saved theme choices retain their selections.
- Shortened bundled theme display names: Classic, Aurelia and Obsidian for player accounts; Aurelia and Dark for the public site. Directory names, persisted identifiers and activation URLs are unchanged.
- The Classic navigation animation regression waits for the real animation after the page swap and checks native interpolation on its actual timeline. It retains intermediate-position and destination checks, with a deliberately delayed response to reproduce the former fixed-window CI failure.
- Theme-specific rendering checks select their intended theme explicitly. Statistics and payment-availability coverage also exercise the newly selected themes; calendar assertions distinguish module content from the global server-status sidebar.

## 0.54.3 - 2026-10-26

**Experimental universal drivers; not production certified.** Updates from 0.54.2. No dependency updates, database migrations or Wallet/Donations changes.

- Character filters now name their source table explicitly and support another table through a pair of character link columns. Existing profiles without a source table keep their original primary-table behavior.
- Related conditions use correlated `EXISTS` instead of joins: duplicate matching records do not duplicate characters, conditions sharing a source/link must match the same record, and missing related records exclude the character. Filter values remain parameter-bound; identifiers and all required schema dependencies are validated before activation.
- Rankings display their primary table/column and explain tie-break sorting in RU/EN. Primary-table ranking sources remain a documented limitation. The constructor shows an explicit table → column → condition → value flow, related link fields, and the scope of filters across public lists and account characters.
- Fixed a mobile layout issue that compressed hero mapping inputs. Added regression coverage for editor persistence, unsafe/missing filter sources, ownership, duplicate rows, EAV-style conditions and real SQL parity.
- Standalone driver validators load the new filter helper without Composer and run as part of the Windows quality gate. Kaev Classic 1.0.3 also synchronizes its particle switch and clears the canvas on the next frame when reduced motion changes before the media callback is delivered; a browser regression simulates that delivery delay.

## 0.54.2 - 2026-10-26

**QA candidate, not production certified.**

Updates from 0.54.0. No migrations, Composer updates, Laravel/Livewire changes, payment/wallet modifications or automatic cutover.

- Universal JSON connection preflight now checks read-only invariants beyond table/column presence: unique login index for account writes, password column length, declared ranking and hero/castle base mappings. Rejections retain the previous verified snapshot and show schema issues in admin diagnostics; checks do not prove actual game-client acceptance.
- Custom GameServer online counting now follows the configured `characters.fields.online` direct column, including a verified snapshot on an old driver identifier. Unsupported online sources return unavailable without querying an unrelated legacy table.
- Saved custom JSON drafts cannot execute online-count queries until explicitly verified and applied to the server.
- Module `driver_extensions` now use only a verified applied snapshot for custom drivers, check declared module tables/columns at enablement and re-check when mappings are read at runtime. Legacy drivers retain their bundled template fallback; one table per extension group.
- LoginServer constructor preserves independently selected capabilities instead of forcing all writes on. Profile editor exposes charset/collation and active capabilities; optional account fields no longer automatically block read-only profiles.
- RU/EN guides rewritten for the actual constructor and operational limitations; previous iteration notes archived. Added offline negative preflight checks and strengthened module regression tests.
- Registration preflight also checks mapped insertion fields and fixed values. Read-only copies retain their selected capabilities and do not require password mappings. Modified legacy-profile overrides require explicit verification before modules can use them. Schema diagnostics are localized and safely escaped.
- Release acceptance includes disposable MySQL/MariaDB parity, PHPUnit/PHPStan, Playwright and installation/update regression tests. Game-client acceptance remains a separate requirement. Account-ID ownership and EAV ranking remain deferred deliberately.


## 0.54.0 - 2026-10-25

`composer.lock` is unchanged; no new migrations. The universal drivers stay experimental. Modules that use the new `driver_extensions` field must require `cms_min` 0.54.0.

- **Universal drivers (experimental).** **Servers → Drivers** has a constructor for game-server and login-server drivers: a catalogue table, import and export, a copy of any driver, and editors where each field's table and column are typed directly (another table is just typed into the row). More password formats are available (SHA-1 and SHA-256 and MD5 as hex, Whirlpool, plus the existing SHA-1/Base64 and BCrypt), and a hash that does not fit its column is refused before writing. A damaged stored driver no longer breaks the page.
- **Modules can ask a driver where their data lives.** A module declares `driver_extensions` in `module.json`; the driver editor shows a "For modules" section for it, and the module will not switch on until the drivers in use have its required answers. See `docs/en/UNIVERSAL_DRIVERS.md`.

## 0.53.11 - 2026-10-25

Universal driver audit and targeted safety hardening, based on 0.53.10 full ZIP. **Experimental; not a stable migration release.**

- Reject JSON profiles that mark the primary accounts/characters table optional, omit mandatory account identity, character owner/ID/name or character-filter columns from the REQUIRED MySQL schema preflight. Validate the LoginServer ownership column against the declared identity mapping.
- Add nine offline negative regression scenarios to protect against false-positive connection compatibility. Validate filter structure before inspecting its required columns.
- Respect locally hidden bundled profile IDs in the LoginServer/GameServer selection registry, not only in the Drivers catalog. Preserve unchanged PHP fallback for existing connections; add a feature test for hide/restore visibility.
- Run owner-approved dependency guard before requiring PHP ZIP when invoking the release builder. The dependency approval regression now passes even on a PHP installation without the ZIP extension; actual release packaging still requires ZIP.
- Document remaining unverified points: real MySQL parity and unique login index, server-side account-ID ownership, EAV ranking, module-driven driver capabilities, old-driver parity, browser/Windows/install/update and complete PHPUnit/PHPStan. Retain the PHP Mobius/RUSaCis drivers and do not switch existing production connections.
- No new or modified migrations, game database writes, Composer dependency changes, framework updates or payment changes.

## 0.53.10 - 2026-10-25

Universal drivers, iteration 8: **opt-in integration and guarded JSON profile activation**. Base: 0.53.9 full ZIP.

- Registered user-defined JSON LoginServer/GameServer profiles in connection selectors. Existing Mobius/RUSaCis identifiers still use their proven PHP paths until explicitly activated with **Apply JSON and verify**; no automatic cutover of existing installations.
- Added database compatibility preflight for newly selected user-defined profiles before modifying server connections, verified immutable per-connection snapshots and stored-config validation. Failed candidate activation preserves the previously verified profile; profile catalog edits do not automatically affect live connections.
- Bound profile approvals to the connection's real database coordinates and decrypted credentials (including inherited LoginServer DB credentials) using keyed HMAC. Stale or tampered snapshots fail closed. Added an **additive** migration to store connection fingerprints, without changing the previous catalog migration.
- Connected verified snapshots to the existing LoginServer account read/write interface and GameServer world/character readers. Public account screens now use verified ownership/capability checks instead of hard-coded paired driver identifiers. Custom account-ID ownership without a verified login-to-ID mapping is deliberately unavailable.
- The legacy Mobius-only character rescue writer is disabled for JSON-active GameServers: an arbitrary profile must never trigger hard-coded coordinate SQL. Shared Wallet/Donations/reward queue semantics and their data are not modified.
- Added server-management controls and RU/EN translations, snapshot-integrity tests, activation/rollback regression scenarios, and deployment documentation.
- **Not production certified:** parity across real Mobius/RUSaCis MySQL installations, PHPUnit/PHPStan, visual/browser regressions and official release-builder checks still need to run through Codex/CI. Full JS/visual and module-specific universal capabilities need validation before stable adoption. No PHP/Composer dependency updates or live payments.

## 0.53.9 - 2026-10-25

Universal drivers, iteration 7: **administrative editor and JSON catalog UI**, based on the 0.53.8 full ZIP; all production Mobius/RUSaCis PHP drivers remain active.

- Added **Servers → Drivers** (LoginServer/GameServer tabs) for offline profile creation, typed field/table/relation editing, optional EAV/choice/operator mappings, ranking/castle/hero settings, import/export of JSON, cloning, removal/hiding, and restoring bundled profiles. Unlike a raw JSON text editor, mappings and allowed operations are edited through form controls. Import is limited to 256 KiB and passes the existing format-v1 allowlist validator.
- Admin permissions: `servers.view` to browse/export; `servers.manage` (non-read-only administrator) for any changes. Livewire methods re-check authorization on every action; actions are audited without logging profile payloads or database credentials.
- Bundled profiles can be edited through local overrides, restored to release defaults, or hidden persistently across CMS upgrades. Profiles referenced by existing connections or verified snapshots cannot be deleted. The editor checks for concurrent conflicting updates, and exact-ID creation cannot overwrite an existing profile.
- Added RU/EN translations, responsive administrator CSS and controller/route/navigation integration. Added feature/regression tests for roles, create/edit/export/delete/restore/import, invalid mappings and deletion protection. PHP dependencies and existing migrations unchanged.
- The experimental adapters are **not switched on for production**. New profiles can be configured and shared offline, but they will not yet appear in the normal LoginServer/GameServer connection selectors. Snapshot verification / safe switch is iteration 8. MySQL parity, full PHPUnit/PHPStan and browser tests need an actual test environment before deployment.

## 0.53.8 - 2026-10-25

Universal drivers, iteration 6: **experimental CMS-side JSON profile catalog and verified connection snapshots**, no change to live PHP resolvers. Base: 0.53.7 full ZIP.

- Added `DriverProfileCatalog` with bundled+custom profiles, persistent local overrides, tombstones for removed bundled profiles, restore to bundled defaults, non-destructive JSON import/export and safeguards against deleting a profile used by LoginServer/GameServer or stored snapshots.
- Added `ConnectionProfileSnapshots` for immutable verified-per-connection JSON + SHA256. Snapshot replacement requires a caller-supplied real schema verification callback; failed checks preserve the last confirmed snapshot. The future administration/server workflows do not yet call this experimental service.
- Added **two additive CMS migrations tables** (`universal_driver_profiles`, `universal_driver_connection_snapshots`). Game databases, existing account/character tables and connection credentials remain unchanged.
- Added feature coverage for offline CRUD, persistence of bundled modifications and tombstones, import identity/name collisions, forbidden deletion, snapshot rollback and corruption. No admin endpoints or UI yet; no release-owned JSON, PHP dependency, or production resolver changes.

## 0.53.7 - 2026-10-25

Universal drivers, iteration 5: **experimental LoginServer account creation and prepared password change**; existing production LoginServer drivers and authorization flows remain unchanged. Base: 0.53.6 full ZIP.

- Added `UniversalLoginAccountWriter` using validated JSON and caller-supplied existing MySQL connection. `preparePassword` supports tested SHA-1/Base64 and BCrypt `$2a$` formats, using a single immutable prepared credential for registration recovery.
- Added bounded, declared, fixed insert values; imported JSON may not remap credentials into arbitrary columns or grant elevated account access. Creation requires supported capabilities, valid fields and a unique login index. Database duplicate exceptions are preserved for the existing `pending/active/failed` recovery logic.
- Added compare-and-set prepared password updates with idempotent retries of the same hash and fail-closed conflicts; this method is not wired to the player account until the later resolver/authorization iteration.
- Added offline writer-safety checks and disposable MySQL fixtures comparing prepared credentials and inserted records against Mobius/RUSaCis legacy drivers; includes duplicate insert, retry, concurrent-stale proof, missing-index and capability tests.
- No framework/Composer updates, published migrations, public routes, production switching or SQL Server/PTS adapter. MySQL integration, browser and full PHPUnit quality gate still require execution in a prepared test environment.

## 0.53.6 - 2026-10-25

Universal drivers, iteration 4: **experimental read-only GameServer rankings, hero lists and castle ownership; production drivers remain unchanged**. Based on the 0.53.5 full ZIP; no Composer changes, published migrations or runtime database changes.

- Added `UniversalGameWorldReader`, which accepts validated JSON and an existing MySQL connection. Level/PvP/PK/play-time rankings, hero records and clan/castle ownership now use configured tables/columns and bounded query-builder bindings; the existing `GameWorldDriverResolver` still uses legacy drivers.
- Added a reusable nine-castle name catalog with RU/EN translations and a safe unknown-ID fallback, used only when the JSON declares `name_source=catalog`. Mobius keeps names from the SQL column.
- Extended additive format-v1 hero and castle metadata with explicit column/relation mappings for generic Java game schemas. No SQL/PHP expression is accepted from the profile. Existing format-v1 files remain valid.
- Added disposable MySQL 8.4 parity tests comparing the JSON reader with Mobius/RUSaCis legacy rankings, heroes, castles and capabilities. Missing optional hero/castle tables, custom JSON table names, ambiguous clan joins and unsafe SQL identifier inputs are also tested. These tests require a real MySQL run and Composer vendor; local syntax-only validation does not prove parity.
- Current limitation: rank sorting uses direct character columns, and non-EAV related display fields are supported. Cross-table/EAV sort expressions are deliberately refused until safe, scalable database-level ordering can be tested. No production feature switch, SQL Server/PTS adapter, write operation, UI or database migration is included.

## 0.53.5 - 2026-10-25

Universal drivers, iteration 3: **experimental read-only GameServer character retrieval; no production switching**. Built on full 0.53.4 source; Composer dependencies and published migrations unchanged.

- Added `UniversalGameCharacterReader`, accepting only validated JSON and an already-open MySQL connection. It reads characters bound to the selected game account, mapped from direct, choice, single-related and EAV fields, with explicit missing-schema diagnostics and bounded queries. JSON does not execute SQL/PHP and cannot select a different server connection.
- Supports RUSaCis deleted-character filtering and active hero flag, Mobius chronicle-based `karma/reputation`, and missing optional data. Ambiguous related rows and duplicate EAV values fail closed.
- Added disposable MySQL 8.4 legacy-vs-universal character parity coverage, EAV and injection/ownership cases to the existing workflow. These require Composer dependencies and live test MySQL; PHP syntax and offline profile validation alone cannot prove parity.
- Kept PHP GameWorldDriver resolvers, user-facing character pages, rankings, heroes/castles and writes untouched. No UI, module or DB migration changes.


## 0.53.4 - 2026-10-25

Universal drivers, iteration 2: **experimental read-only LoginServer path; no production switching**. Based on full 0.53.3 source. Composer dependencies and published migrations unchanged.

- Added `UniversalLoginAccountReader` for validated JSON-driven MySQL account lookup, status/activity summary, prepared account comparison, and mandatory/optional schema diagnostics. Profile input is validated and login values are bound by Laravel Query Builder; configured email identity fails closed if unavailable.
- Split optional LoginServer metadata (`created_time`, `lastactive`, `accessLevel`, etc.) from required account fields in bundled JSON contracts while preserving the old registry schemas and all five format-v1 bundled profiles.
- Added regression/negative tests and direct MySQL fixture parity against existing RUSaCis/Mobius/Mobius Legacy PHP drivers, including unknown accounts, blocked status, mismatched credentials, injected login strings and missing metadata. Test databases are unique per run and cleaned up.
- Extended MySQL 8.4 CI job to install locked dependencies and perform live legacy-vs-JSON read comparisons. Added RU/EN implementation and test documentation.
- **Not enabled:** no legacy resolver/gateway switch, registration, password writes, GameServer queries, modules, server connection settings or DB migrations changed. MySQL CI parity must be run before declaring functional equivalence.


## 0.53.3 - 2026-10-25

Universal drivers, iteration 1 (experimental data contract; **no production driver switching**). No dependencies, game accounts, wallet flows or database migrations changed.

- Added strict JSON profile format v1, offline validator and initial explicit format migrator (unknown versions rejected). Bundled profiles describe Mobius LoginServer, Mobius Legacy LoginServer, RUSaCis LoginServer, Mobius GameServer and RUSaCis GameServer. They are currently read-only **reference files**, not runtime drivers.
- Added hostile-import and registry/schema-parity unit tests, dependency-free contract smoke tests, disposable MySQL 8.4 fixture job with characters, accounts, hero and castle relations, and RU/EN format documentation.
- Documented legacy-only special cases: RUSaCis heroes (`herountil`/`heroes`), Mobius karma/reputation selection, castle names and cross-server ownership. Actual query-equivalence work starts in the following iterations.


## 0.53.2 - 2026-10-25

`composer.lock` changes only by Livewire 4.4.7 → 4.4.8 (Laravel 13.37 is unchanged). One new migration (Donations) runs from the module page. Account theme versions: Luxury 1.6.9, Kaev Aurelia 1.6.11, Kaev Classic 1.0.2; public theme Kaev Aurelia 1.1.0; Donations module 1.16.2.

- **Mail modes on ordinary hosting explain themselves.** A failed check of the asynchronous or database-queue mode now says why (background processes forbidden, no PHP 8.3+ found, cron never ran or stopped) and what to do, instead of "not supported". **Settings → Mail → Delivery mode** also builds the cron line from the real `artisan` location (split installs included) and the PHP 8.3+ binary found on the server, with hints for Plesk, cPanel, DirectAdmin and ISPmanager, and a "Check cron" button that waits for a real Scheduler run to prove cron works.
- **Livewire 4.4.8.** Dependency update.
- **Kaev Classic, a new player account theme.** Warm light and moonlit dark schemes with lavender accents, large readable text, one-line menu with icons, server status at the foot of the menu, an overview banner whose artwork and particles follow the scheme, and a sun/moon switch that crossfades between the two portraits. Menus (profile, wallet, language) open smoothly.
- **Kaev Aurelia has a night scheme.** A sun/moon switch in the header (and at the end of the phone menu) turns the site dark with the night elf portrait and lavender particles. The choice is remembered and applied before the first paint, so it never flashes. After a reload the portrait and the copy ease in instead of snapping in; the language menu opens smoothly. Theme version 1.1.0.
- **The K logo is the default.** Kaev Aurelia, Aurelia Account, Classic and Luxury show the bundled K until a logo is uploaded in **Settings → Site**; a square logo keeps the site name beside it, a wide one takes the whole row.
- **"Личный кабинет" instead of "Личный кабинет игрока"** in the account sidebar and its accessibility labels.
- **Deleting a game server no longer fails with HTTP 500** when the Donations module holds exchange rates for it; they are removed together with the server (new migration).
- A coin in a closed account menu no longer stays faint after the first paint.

## 0.53.1 - 2026-10-23

`composer.lock` is unchanged; no new migrations.

- **About and Download are ordinary pages.** Theme buttons open a page by its address key: "About the server" the page with the address `about`, "Download client" the page with the address `downloads`, through the new `page_key_url()` helper (`download_page_url()` remains). Create a page with that address and the button picks it up; without a published page the button stays and its address answers "not found". Both pages can be edited, unpublished or deleted without warnings; the page list no longer shows "check the download page" notices or template buttons, the page form no longer has special download or About blocks, and the About page no longer appends game server cards under its text. The old `/about` and `/downloads` addresses redirect to the pages with these keys. New installations still get both as example pages, the About text without the line about server details below.
- **One address in every language.** When a translation's address is left empty, the page takes the address typed in another language (the default one first) instead of making a new one from that language's title, so `downloads` stays `downloads` in English too.
- **New pages are published on save.** The "Publish" switch is on by default when creating a page; untick it to keep a draft.
- The RuSAcis statistics test checks only visible text, so a random CSRF token can no longer fail it.

## 0.53.0 - 2026-10-23

`composer.lock` is unchanged (Laravel 13.37, Livewire 4.4.7). One new migration runs automatically: the reserved About page. Account theme versions: Luxury 1.6.8, Kaev Aurelia 1.6.10; public themes Kaev Aurelia 1.0.14, L2 Dark Classic 0.8.5.

- **A proper "no news yet" block.** With no published news the Kaev Aurelia home page showed a single card with a cropped logo, and the news page a bare line of text. Both now show one block across the news area, "No news yet" and a short line about what will appear there.
- **Test button for YooMoney notifications.** **Donations → Payment systems → ЮMoney** has a "Test" button next to the notification address. It opens a window that waits up to five minutes for the test notification sent with "Test" in YooMoney's HTTP notification settings, then shows a green tick when the address and the secret match (and saves the settings form) or a red cross when the signature does not match. The secret typed in the form is the one tested, or the saved one when the field is empty. Real payments are untouched: only a notification marked as a test and carrying no order label is answered by the test window, and only while it waits; everything else takes the usual verification and settlement path.
- **The control panel shows the site name.** The sidebar, the mobile toolbar, the browser tab title and the sign-in screens used the application name ("KaevCMS") instead of the name set in **Settings → Site**.
- **Settings images end with a "Delete" button.** The logo, favicon and wallet icon cards replace the "Remove current …" checkbox with a "Delete" button at the bottom; it marks the image for removal on save, the preview fades, and "Do not delete" takes the mark back.
- **Top-up only from the wallet.** The player account sidebar no longer lists "Add coins": the wallet page and the header wallet menu already open the top-up. The top-up and exchange dialogs have a centred close icon and a thin scrollbar inside the frame.
- **Quiet scrollbars in the player account.** The sidebar and the support chat use a thin scrollbar that takes the theme accent on hover; reaching their end no longer scrolls the page behind them.

- **One button for the admin theme.** The light, dark and system buttons in the admin header became one button that steps light → dark → system, showing the sun, the moon or the monitor. The whole page cross-fades into the new theme in one step (before, every element faded on its own and text flickered), while the old icon slides up and the next one rises in its place; with reduced motion, or in browsers without view transitions, the theme simply switches. The dark theme is now neutral charcoal instead of navy slate (page `#0e0f11`, cards `#16171a`, sidebar `#0a0b0c`); the status colours are unchanged. Main buttons are monochrome instead of bright blue (near-black in the light theme, near-white with dark text in the dark one), and a selected tab or page is a neutral raised chip; blue stays for links, focus, switches and charts.

- **Player support tickets look like a chat.** A ticket shows the conversation grouped by day with the player's messages on the right, a messenger-style field that grows with the text, a round send button and Ctrl + Enter to send; sending no longer shows a banner, and the cursor stays in the field. The number, subject, category, assignee and dates sit in a details panel beside the chat. The new ticket form picks the category from tiles with icons, keeps the character counters inside the fields and lists short tips on what to include.

- **The online chart shows each game server.** Instead of "peak" and "average" lines it shows the total online and, with several game servers, one line per server; a "Servers" menu with checkboxes chooses the visible lines and is remembered. Days and hours show their highest online, the 5-hour and 1-hour periods the online of every five minutes. The total of a step is the sum of the servers at that step.

- **The About the server page is editable.** `/about` showed fixed theme text ending with "its content will be editable in the CMS later". It now shows an editable RU/EN page from **Pages** (reserved `about` address, created once on install or upgrade) above the game server cards, which still come from the game server settings. Without a published page `/about` shows the cards alone; a deleted page is not recreated by upgrades and can be restored with **Create about page** in the page list. `/ru/pages/about` redirects to `/about`.
- **Language menu instead of language buttons.** The website, the player account and the administration showed one button per language (RU / EN). A compact button with the current code now opens a list of the enabled languages by their own names, so more languages take no extra room; it closes on an outside click or Escape. On phones the public menu ends with it (both themes hid the header switcher there with no replacement), and the player account keeps it on phones too. The Luxury account theme, which had no language choice at all, gets it as well. Theme versions, so browsers load the new styles: Kaev Aurelia 1.0.14, L2 Dark Classic 0.8.5, account themes Luxury 1.6.8 and Kaev Aurelia 1.6.10.

- **Wide logo.** **Settings → Site** has a "Wide logo" box under the logo. Ticked, the logo fills the top of the control panel menu with "Control panel" under it, and the top of the player account menu in both account themes, without the site name beside it; on phones the admin toolbar shows the logo alone. When a newly chosen image is much wider than tall, the box is softly highlighted as a hint; it is never ticked automatically. The sidebar mark shows the uploaded logo (any shape) instead of the first letter, and the header avatar is neutral instead of blue.

## 0.52.0 - 2026-10-22

Bundled module and theme versions are unchanged; `composer.lock` is unchanged (Laravel 13.37, Livewire 4.4.7). Two new migrations run automatically: the online history table and the timestamp repair below.

- **Donations installs and updates on MariaDB before 10.10 and MySQL 5.7.** These servers run with `explicit_defaults_for_timestamp` off, which kept the Donations module from installing in 0.51.3 and silently added `ON UPDATE CURRENT_TIMESTAMP` to ten timestamp columns (mail queue, reward requests and grants, daily reward claims, promo code activations, support tickets and service records). The MySQL schema grammar now declares such columns the MySQL 8 way, and a migration removes the accidental auto-update; values already overwritten cannot be recovered. MySQL 8 and MariaDB 10.10+ were not affected.
- **Faster site.** Remembered table checks, settings read in one query, module migrations read once, the downloads page read once per request, no translation-file parsing or module state writes per request, game database schema read once per table, remembered avatar and item icon lookups. On a 1 vCPU test server 20 concurrent visitors get 14.8 pages per second instead of 6.6, with no errors; a cold characters page needs 8 game database queries instead of 63.
- **Laravel caches are built automatically on production sites.** Administrators no longer run `php artisan optimize`; installs and updates clear the caches and the next request rebuilds them. Database values and stored secrets never reach the caches, and module pages survive the route cache (they returned 404 after `php artisan optimize` before).
- **The mail queue survives a provider outage.** An unreachable SMTP provider pauses the queue instead of stalling it, mail is retried for 30 minutes, and the SMTP timeout is 10 seconds. The mail and VDS guides describe provider outages and the nginx `fastcgi_read_timeout`.
- **New administration look.** Inter is shipped with the CMS; hairline borders instead of shadows, larger titles and figures, line icons for every menu entry, menu groups slide open, and the menu no longer shifts when it starts to scroll. `docs/*/DESIGN.md` describes the design rules; numbers, sizes and percentages follow Russian typography.
- **New dashboard.** Key figures for master accounts, game accounts, characters in game and top-ups; charts for online players, registrations and top-ups with periods from 30 days to 1 hour, toggleable lines and details that can be pinned with a click; a "Needs attention" list, servers, newest master accounts, latest top-ups and a system summary. Online history is recorded every five minutes and kept for 35 days.
- **Modules:** enabled module cards have an Open button, and disabling a module asks for confirmation.
- Fixed: search fields in admin filter bars, the reward queue overview and filters, currency names breaking apart in the Donations settings, and the promo code history widening the player account on phones.
- Unused code, views and assets were removed; 33 documentation stubs were merged into the main guides and history before 0.42.4 moved to `docs/CHANGELOG-ARCHIVE.md`.

## 0.51.3 - 2026-10-21

0.51.2 was never published; this release supersedes it. Bundled module and theme versions are unchanged; `composer.lock` is unchanged (Laravel 13.37, Livewire 4.4.7).

- **Uploading an update no longer fails with HTTP 413 on shared hosting.** Hosts often accept only 2–8 MB per request (`upload_max_filesize`, `post_max_size`), while the cumulative package with dependencies is about 16 MB. The updates page now sends the package in parts below the server's limit and the server assembles it before the usual inspection, so no PHP settings need changing. Verified locally with a 2 MB limit: the 15.6 MB cumulative package arrived in 9 parts and was staged. Sites on 0.42.4–0.50.16 get this uploader from the bridge package, which is small enough for default limits.
- **Composer dependencies change only with the owner's explicit approval.** `composer.json` opens with a bilingual STOP warning (a top-level key: `extra` is part of `composer.lock`'s content-hash). The approved fingerprint lives in `deployment/dependencies/approved-dependencies.json`; `deployment/dependencies/dependency-guard.php` fails the Windows quality gate, GitHub Actions and release builds on any unapproved change to `composer.json` or `composer.lock`. Approval is typed by the owner in an interactive terminal (`--approve --reason=...`). `composer install` from the approved lock is not affected; dependencies and `composer.lock` are unchanged.
- New **Package from the server folder** on the updates page: copy the ZIP through FTP or the file manager into `storage/app/kaevcms/updates/incoming` and verify it from the panel — same inspection as an upload. Without JavaScript, a request above `post_max_size` returns to the updates page with instructions instead of a bare "413 Content Too Large" page.
- The cumulative package also accepts sites on 0.51.1 (an internal test build) and 0.51.2.

## 0.51.2 - 2026-10-20

0.51.1 was used only for an internal dependency-swap test build and is skipped. Bundled module and theme versions are unchanged; `composer.lock` is unchanged.

- **Bridge package for sites on 0.42.4–0.50.16**, including shared hosting without SSH. Their Web Updater cannot replace dependencies and refuses packages with another `composer.lock`. Each release now also ships `KaevCMS-bridge-update-0.42.4-0.50.16-for-<version>.zip`: it installs the current code and the dependency-aware updater without touching `composer.lock` or `vendor`, so old updaters accept it; the regular cumulative package installed right after replaces the dependencies. The current code was verified on the pre-0.50.11 dependencies (Laravel 13.19, Livewire 4.2): PHPUnit and the browser gate pass.
- Fix the CLI updater (`kaevcms:update`) for dependency-changing packages: it now finishes the update in a fresh process like the VDS agent, instead of reporting success while the site stayed in maintenance.
- Verified on a live MySQL 8.4 VDS: 0.50.16 → 0.51.0 through the legacy updater, a dependency swap (Laravel 13.37 → 13.38) through the VDS agent, and a forced migration failure rolled back database, files and `vendor` byte-identically.

## 0.51.0 - 2026-10-20

- **Web Update can now cross Composer dependency changes.** The cumulative package carries the production `vendor` tree built from the release's `composer.lock` (about +11 MB compressed). When a site's dependencies differ, the updater checks PHP and extensions first, unpacks the new tree beside the live one, swaps it in with two renames, and finishes migrations in a fresh PHP process (VDS agent: `kaevcms:update-finalize`; panel: automatic signed redirect through maintenance mode). Database, files and dependencies roll back together on failure. Works on shared hosting without SSH or Composer. Sites whose dependencies already match keep their `vendor` untouched.
- The bundle is declared as an ordinary payload file (`core/deployment/updates/dependency-bundle/vendor.zip`), so updaters before this release still accept the package when dependencies are unchanged; the new updater consumes it instead of installing it and removes copies left by older ones.
- The release builder installs `composer install --no-dev` from `composer.lock` into a temporary directory, verifies the tree matches the lock, and packs it deterministically; required extensions are derived from the locked packages (polyfill-provided ones excluded).
- New and documented commands: `kaevcms:update-finalize`; `kaevcms:update-recover` also restores `vendor`. The update page offers "Finish the update" if the browser was closed between steps.

## 0.50.16 - 2026-10-20

- Fix HTTP 500 on **Settings → System updates** after a completed update on MySQL 8. Every update record stores the full package manifest (about 290 KB for a cumulative package), and sorting the history by `created_at` exceeded MySQL's default 256 KB sort buffer (`Out of sort memory`, error 1038). The update lists and the diagnostic package now sort by record id, which MySQL reads straight from the primary key without a filesort. Bundled module and theme versions are unchanged from 0.50.15.
- **If the System updates page already shows 500 on your site**, the page cannot be used to upload this fix. Install it once with the CLI updater as the project owner (`php artisan kaevcms:update /path/KaevCMS-cumulative-update-….zip`), or temporarily raise MySQL `sort_buffer_size` to 1M, update from the panel and restore the setting.

## 0.50.15 - 2026-10-20

Bundled versions: Daily Rewards 1.3.3, Donations 1.16.1, Promo Codes 1.4.3, Support Tickets 1.7.3, Kaev Aurelia Account 1.6.9, L2 Obsidian Luxury 1.6.7. Updated modules ask the Owner to apply their update in **Modules** after the site update. No new database migrations; `composer.lock` is unchanged.

- Keep player pages responsive when a game or login database is down or stalled.
  - External connections now open with a bounded network read timeout (`EXTERNAL_DB_READ_TIMEOUT_SECONDS`, default 20 s, instead of the mysqlnd default of 24 h) and a shorter server-side row-lock wait (`EXTERNAL_DB_LOCK_WAIT_TIMEOUT_SECONDS`, default 10 s, always below the read timeout), so a blocked reward or rescue write is rejected cleanly by the server instead of being cut off mid-flight.
  - A server-wide cooldown (`EXTERNAL_DB_UNAVAILABLE_COOLDOWN_SECONDS`, default 30 s) makes every player and game account skip an unavailable server instead of each waiting for the same timeout.
  - The game account page reuses the character directory's short cache and cooldown instead of querying every game server on each view.

- Sign out a player's other browsers after a password change or reset. A new `AuthenticatePlayerSession` middleware binds the player login to its password hash (also checked for remember-me cookies); the browser that changed the password stays signed in, sessions created before this release adopt the hash instead of being signed out, and an administrator login in the same browser is not affected.

- Add `php artisan kaevcms:update-recover [uuid] [--yes]`, a server-side fallback for a stuck Web Update. Run as the project owner, it rolls back an interrupted update from the verified backup (the panel's recovery runs as PHP-FPM and cannot rewrite owner files on a VDS), cancels a VDS agent request that was never started, or lifts leftover maintenance mode. It refuses to run as a different user than the file owner and needs explicit confirmation. Documented in the RU/EN update guides.

- Fix MySQL/MariaDB Web Update rollback. Backups wrote every value as a bare `0x…` binary literal: MySQL 8 rejects those for JSON columns, numeric columns could restore the character code instead of the number, empty strings produced an invalid bare `0x`, and generated columns were inserted although the server must compute them. Character, numeric, temporal and JSON values are now restored through `CONVERT(0x… USING <connection charset>)`, binary/BIT/spatial columns keep raw hex (`X''` when empty), and generated columns are skipped. BIT values (returned by PDO as integers) are restored numerically and DOUBLE values keep full precision. Restore validates the whole backup before dropping any table and keeps explicit zero AUTO_INCREMENT values. Older backups remain restorable as before. The fix protects updates started from this version onwards, because an update runs the installed version's backup code.
- Discard stale SQLite `-wal`/`-shm`/`-journal` sidecars before restoring an update backup so they cannot be replayed onto the restored file.
- Add `tests/Integration/Updates/mysql-backup-roundtrip.php`, a real MySQL/MariaDB backup-and-restore gate for a disposable test database. Verified on MySQL 8.4.11: the gate passes, and on the full KaevCMS schema the previous code failed with error 3144 and left 0 of 60 rows, while the new code restored all 39 tables byte-identically.

- Bring every bundled module's administration pages to the Settings/Mail canon: section tabs (`x-admin.tabs`) directly under a header that always has a title and a description, so the header no longer changes height between tabs.
  - Donations: replace the custom underline navigation and "Back to modules" link with shared tabs; add header descriptions to Providers, Orders and Order detail, and a short Exchange description (the long help moves into the exchange card).
  - Daily Rewards and Promo Codes: "Calendars"/"Promo codes" and journal tabs replace the "Open journal" and "Back" buttons; promo current/archive/all filters become subtle sub-tabs.
  - Support Tickets: "Tickets"/"Settings" (owner only) tabs replace the Settings and "Back to tickets" buttons; the settings General/Cleanup tabs become subtle sub-tabs.
- Fix the AdminPanelTest module-catalogue assertion, which still expected the pre-card `module-list` markup.

- Smooth the mobile account menu in both account themes: the dim-and-blur backdrop now covers the whole page beneath the opaque drawer and fades in together with it (0.32 s ease-out, blur 0 → 3 px), instead of a crisp page under the sliding drawer and a sudden blurred strip beside it. The closed drawer is fully hidden, so its shadow no longer peeks at the screen edge. The Playwright layering regression now checks real hit-testing (drawer on top, backdrop under the rest of the page) rather than the old backdrop offset.

- Open the account balance menu and the profile menu from the same line in both account themes: on desktop the shorter balance pill now drops from the shared top-bar edge, and on phones both menus use the same fixed position.
- Remove the redundant explanation under "Create your first game account" on the account overview in both themes.

- Collapse the module capability list (autoload, services, views, translations, migrations, routes) behind a "Details" toggle with a count inside the module technical-details menu, so version, author and migration status stay readable at a glance.

- Preserve transparent module artwork edges by removing the preview container background, border and clipping.
- Present modules in a responsive card grid with four columns on wide screens. Keep controls and errors visible, and move technical metadata, capabilities and migration counts into the three-dot disclosure.
- Replace all four bundled module icons with the supplied artwork, add Donations artwork, support square 320×320 and 512×512 WebP covers, and refresh cached catalogue images when their contents change.

## 0.50.14 - 2026-10-18

- Keep the player exchange dialog stable while editing the amount: the received-item preview remains visible at zero and invalid submissions stay disabled.
- Keep reward history and section descriptions within the account viewport on mobile, including long promo codes.

- Open player coin transfers in a shared Donations dialog from the wallet menu and wallet page, simplify the form, and show success with an inventory hand-off. Keep exchange history in the wallet journal and compact the inventory character-transfer controls.

- Add a direct game-coin exchange link to the shared player wallet menu when a server has exchange enabled, independently of wallet top-up availability. Use the current wallet coin icon for history entries in both account themes.

- Make Donations exchange settings visual with the current wallet coin, server-aware item names and compact inline icon previews, and one atomic Save settings action for all servers. Preserve per-server exchange rules and read-only administrator access.

- Add a fully editable RU/EN download page: card titles, descriptions and buttons are stored in page content and edited visually. Existing download cards and links migrate into the editor once without replacing owner text. Page editors can insert/remove cards and style links as buttons. The reserved downloads page address connects directly to Download client in both themes; admins can recreate it from a prefilled form or create a page with that address. Existing content and file links survive updates.
- Use the bundled transparent Kaev coin as the default wallet icon throughout administration and both account themes; removing an uploaded icon restores it. Reveal the mobile Aurelia portrait above the hero text while preserving scenery across navigation.

- Keep Aurelia scenery and its pale overlay continuous during public-page navigation. Unify account summary cards and tabs in both themes, and hide theme/version metadata from players.

- Simplify player wallet history: one timeline for purchases, other credits, spending and pending top-ups; inline payment/cancellation actions, expiry countdown and optional inactive attempts. Purchase credits appear once; no settlement or ledger-write changes.

- Localize bundled module catalogue names/descriptions and audit activity labels in RU/EN, including stored events from disabled modules; stable action codes remain unchanged.
- Add a shared account balance dropdown with direct top-up and transaction-log links in both themes. Make top-up order history and wallet operations visible from the top-up form.

## 0.50.13 - 2026-10-18

### Added — simple in-game coin exchange (Donations 1.15.0)

- Optional game-coin exchange configured entirely in Donations administration, separately per game server: in-game item ID, number of items per CMS coin, enabled/off. Defaults off, no `.env` editing.
- Player chooses server and integer CMS coin quantity, sees recalculated amount and receives the corresponding ordinary game item in Web Inventory (not a direct game database credit). Existing reward queue / character delivery is unchanged.
- Persistent immutable exchange snapshots, distinct trusted Wallet debit source, unique request UUID and atomic wallet debit + inventory grant + audit on primary CMS DB; failure rolls everything back.
- Exchange can be used without live YooMoney payments and without the full Shop module. Existing Wallet transaction engine and all published migrations preserved; one additive Donations migration.
- Automated feature regressions included; full PHPUnit/Playwright/MySQL concurrency still require the full quality gates.

## 0.50.12 - 2026-10-17

- Simplified YooMoney setup to one form with a visible wallet number, saved-secret indicator and one payment switch; separate provider/test/pilot controls are no longer exposed in the YooMoney admin UI.
- Players create an order and proceed directly to checkout when the only active provider is YooMoney; no unpaid-test-draft buttons or internal mode labels on the player page.
- Removed noisy diagnostics from the provider screen; clearly explain why an external signed webhook cannot reach localhost. Provider setup is saved even when activation readiness is missing.
- Kept authenticated HMAC callbacks, fee separation, immutable order pricing, idempotent Wallet settlement and ongoing processing of in-flight payments when new sales are turned off.
- Added regression coverage for simple admin settings and direct player checkout; legacy migration and Wallet Core remain unchanged.

## 0.50.11 - 2026-10-17

### Added — Donations activation from administration panel

- Donations 1.13.0: audit-logged payment activation (off, one-player pilot, all players) from the existing YooMoney admin page; no donation-specific `.env` flags or Artisan commands.
- New additive `module_donation_live_settings` migration; defaults off. Existing migrations and Wallet transaction engine untouched.
- Live readiness validates provider, active RUB tariff, visible account page, HTTPS, production/debug and DB. User search for pilot account, Owner/Admin control, Auditor read-only.
- Signed YooMoney callback / in-flight settlement stays live after disabling new checkout. Error and negative regression tests added for modes, permissions and invalid pilot.
- Live merchant acceptance is NOT claimed until a genuine approved transfer is completed and verified.

## 0.50.10 - 2026-10-17

### Added — YooMoney monitored live launch (Donations 1.12.1)

- Existing production checkout, signed HMAC-SHA256 callback and exactly-once Wallet credit remain available behind the four opt-in deployment flags. No merchant credentials are bundled and no real transfer was executed in this environment.
- Optional `DONATIONS_LIVE_TEST_USER_ID=<CMS user ID>` restricts only new player checkout to one account for the first live transfer. All other users see preview-only UI; protected GET checkout denies them. Invalid pilot IDs fail closed.
- Stored signed callbacks and settlement do **not** depend on the current pilot account; receiving delayed payments works after the pilot scope changes.
- Read-only readiness command reports whether pilot-only or all-player checkout is configured. Default gates remain false; no automatically enabled live transfers.
- Added feature regressions for pilot-vs-nonpilot checkout, malformed restrictions and return to public checkout. No new database migration, no Wallet Core changes.

## 0.50.9 - 2026-10-17

### Added — Donations C6 operational activation (module 1.12.0)

- Four independent secure deployment gates for trusted YooMoney checkout/callback/credit and Wallet source now read explicitly from `.env`, instead of being hardcoded to false. All still default to **false**, including on upgraded installations; no live transfers are enabled by this release.
- Added read-only `php artisan kaevcms:donations-readiness`: local production/HTTPS/DB/module/provider/RUB-tariff/credential/gate checks, strict nonzero exit when blocked, no secret values or API calls. Green local status does not prove actual callback reachability or a real payment.
- Changed admin and player descriptions to show the actual live vs unpaid preview state. Original fee checks, signed notifications, idempotent Wallet settlement and recovery remain unchanged.
- Added release-gate/diagnostic regressions. Protected deployment contract includes the four opt-in values; previous financial migrations and Wallet Core are unchanged.
- Live activation requires separate final Windows/Linux and MySQL/MariaDB QA and one user-approved small-value transfer; no online merchant API or live transfer tested in this build environment.

## 0.50.8 - 2026-10-17

### Fixed — Donations C5 QA stabilization (module 1.11.1)

- Preserved active Administrator rights to manually report Donations refunds/disputes without granting global `ModulesManage`; retained Auditor read-only and Editor denial. Added regression coverage for permitted/denied writes, audit and Wallet invariants.
- Preserved checkout `Referrer-Policy: no-referrer` across global security middleware, and removed the duplicate checkout heading. Tested headers, DOM and repeated checkout reads.
- Kept RU/EN language switching on the same Donations page with calculator query parameters, without breaking existing Daily Rewards navigation or accepting unsafe encoded paths.
- Isolated throttle buckets for language switching, payment callback and distinct Donations actions. Existing numeric limits remain unchanged. Browser regressions cover formerly competing requests and expected 429 responses.
- Fixed MySQL/MariaDB payment-test setup to apply module schema before per-test transactions. Expanded concurrent signed callback tests via the full Laravel HTTP kernel, plus two themes and RU/EN on desktop/mobile.
- Integrated reviewed fixes from the 0.50.7 candidate; published them under a new release and module version. Core Wallet, all published migrations, independent currency tariffs, and the four disabled production payment gates are unchanged.
- Acceptance evidence: the candidate review reported 1121 passed / 11 skipped on Windows PHPUnit; 1132 passed on Linux; 41 payment tests each on MariaDB and MySQL; and 78 Playwright tests. These outcomes were recorded for the unreleased candidate, not independently reproduced against this packaged 0.50.8 tree. Live merchant/API payment was not tested.
- **Next steps:** verify the final release ZIP with the Windows scripts and disposable Docker MySQL/MariaDB, then run a separately approved low-value live YooMoney transfer. Do not enable live payment gates for public users until this final validation succeeds.

## 0.50.7 - 2026-10-17

### Added — Donations C5, gated YooMoney checkout (module 1.11.0)

- Added `/modules/donations/orders/{orderUuid}/checkout` under existing authenticated, verified player routes. A read-only checkout page prepares official YooMoney `POST https://yoomoney.ru/quickpay/confirm` forms for wallet (`PC`) or card (`AC`). The user must explicitly submit the form on YooMoney; rendering a page never transfers money.
- Prices, currency, merchant receiver, UUID label and return URL are rebuilt from the owned immutable order/preparation and trusted encrypted provider configuration; browser `sum`, `receiver`, `label` and `paymentType` query values cannot change the server-side form. Browser redirects do **not** credit Wallet; only the existing HMAC-verified settlement chain can.
- **Fail-closed delivery:** the new `checkout_enabled` flag AND existing `callbacks_enabled`, `credits_enabled` and `wallet.enabled_sources.donations` must all be true before checkout can render. **All four remain FALSE in the shipped release.** Reject other users, expired/cancelled orders, disabled/test-mode providers, mismatched order snapshots and orders with existing verified payment receipts. No automatic activation or real payments.
- Added clear RU/EN checkout copy and admin webhook URL for merchant setup, non-cacheable checkout responses, and feature tests for default-disabled gates, forged browser parameters, receipt-free checkout, expiration, provider shutdown and user isolation. Existing migrations and Wallet Core unchanged.
- **QA required:** PHPUnit/Pint/PHPStan, Windows/browser suites, MySQL/MariaDB concurrency and clean/upgrade tests on a dedicated environment. Never enable live gates based solely on syntax checks or provider-local diagnostics.

## 0.50.6 - 2026-10-17

### Fixed — Donations C4d, payment receipt recovery (module 1.10.1)

- Previously authenticated, durable signed YooMoney receipts are stored with a Laravel-Crypt-authenticated, immutable verification snapshot. Deferred settlement no longer fails if the provider rotates its HMAC signing secret and its 24-hour acceptance grace expires **after** the initial successful verification.
- On retry, the saved evidence is checked against its original receipt ID, operation ID, order, currency, exact payer and net amounts, and verification fingerprint. Corrupted or swapped ciphertext fails closed. WalletGateway, financial migrations, price snapshots, and exactly-once settlement stay unchanged.
- Existing pre-0.50.6 receipts containing only encrypted raw POST bodies remain readable: they still require an eligible signing secret for re-verification. Unauthenticated provider-history candidates can **never** use the deferred-credit path. A new callback using an expired old key remains invalid.
- Added feature regression tests for expired key rotation, corrupted/swapped evidence and legacy receipts; expanded standalone YooMoney HMAC and verified-evidence contract test. Locally executed standalone and available installer/shared-hosting regressions; Composer/PHPUnit/Pint/PHPStan, Windows/Playwright, ZIP-extension release-builder tests and isolated MySQL concurrency/upgrade remain unverified here.
- Checkout, callback processing and coin-credit feature gates remain OFF by default. Do not accept real transfers before complete QA and explicit live enablement.

## 0.50.5 - 2026-10-17

### Changed — Donations C4c (module 1.10.0)

- **Bounded YooMoney HMAC key rotation:** when an administrator replaces the notification signing secret, the prior secret is retained only encrypted in the existing provider credentials payload for **24 hours**. Earlier immutable **live** preparations can verify delayed signed callbacks with it. Preparations created after rotation cannot use previous keys: an immutable preparation-ID cutoff (not seconds-precision timestamps) closes the same-second race; test preparations remain non-creditable. Up to four recently retired keys may exist during repeated rotations. Blank secret inputs still retain the current credential. No additional admin controls or database migrations were introduced.
- Verification uses the current signing secret first; only an actual signature mismatch permits trying an eligible retired key. Malformed callbacks, duplicate parameters, wrong amounts and wrong currencies remain rejected or reviewed without creating extra Wallet credits. Previous keys are never included in provider UI, public API, audit logs or `credentialsForDriver` results. Retired keys expire automatically and are dropped on the next provider-settings save; old lost transfers beyond the grace window require explicit read-only recovery/manual investigation. This grace is intended for ordinary rotations, **not suspected credential compromise**; isolate callbacks/credits during an incident.
- Read-only YooMoney history reconciliation now starts from the most recently prepared orders, rather than repeatedly scanning only the earliest limited batch. Candidates remain administrative evidence, **never a source for automatic credit**. Use the existing `--order` option to inspect older specific orders.
- Added regression tests for key rotation with old/new orders, expired grace, disabled provider, recovery order, and old HMAC rejection. No changes to existing migrations or Core Wallet.
- **Live checkout, callback and credit gates remain disabled**. PHPUnit, Pint, PHPStan, Windows browser gates and isolated MySQL/MariaDB concurrency/upgrade remain to be executed in the target environment before considering real payments.

## 0.50.4 - 2026-10-17

### Changed — Donations C4b (module 1.9.0)

- New **unpaid draft** expiration window is 60 minutes instead of 30. Previously persisted deadlines do not change: an existing order keeps its original `expires_at` snapshot and new drafts receive 60 minutes. This window limits initiating payment, **not delivery of a signed callback**.
- Correctly HMAC-verified YooMoney transfer for a prepared live order can complete **after the deadline or local cancellation/expiry**, provided its immutable order UUID, currency, payer `withdraw_amount`, and preparation snapshot all match. The credit path still uses one WalletGateway operation and a unique settlement per order with transactional audit. A second different incoming transfer for a credited order remains manual review; invalid signatures/test notifications/amount mismatch never credit.
- Current provider test/live toggle or disable does not retroactively reject a valid signed transfer with a prior immutable **live** preparation. A previous **test** preparation always remains non-creditable even when the provider is subsequently switched to live. Credential rotation still requires separately planned historic-signature support before live release.
- More useful UI statuses: pending signed receipts show **Processing**, mismatched authenticated receipts show **Payment needs review**, settled orders show **Credited**. Fee reporting continues to distinguish payer `withdraw_amount` and receiver `amount` without float.
- Added regression tests for 60-minute expiry, delayed valid payments from expired/cancelled prepared orders, mode flip with closed sales, status display, and replay protection. No new migrations; published schemas and Wallet Core remain untouched.
- **Production checkout, callback and credit switches remain disabled.** This is an incremental, unaccepted C4b build; testing with PHPUnit/Pint/PHPStan/Playwright and disposable MySQL/MariaDB clean install/upgrade/concurrency is still required. No true live payment performed.

## 0.50.3 - 2026-10-17

### Added — Donations C4a (`donations` 1.8.0)

- Exact signed YooMoney receipt accounting displayed per order: payer `withdraw_amount`, recipient `amount`, and the **difference** in RUB minor units. The difference is an observed fee/deduction, not a published fixed rate or permission to surcharge the payer.
- New additive `module_donation_payment_review_events` journal for **administrator-reported, unverified** disputes, refunds, chargebacks, review notes and resolution notes; every event references a receipt/order/admin, has an idempotency UUID, preserves the human reason and requires an atomic audit record. No Wallet changes and no automatic refund or debit.
- Owner/Administrator-only report action with server-side permission checks, Auditor read-only, Editor denied. Administrative order page displays history; list can be filtered to orders with reports. Test and cross-order receipts cannot be associated with a review. Refund/chargeback reports require a credited receipt.
- Negative regression tests for replay/conflicting key, roles, forged order/receipt association, audit rollback and read-only Wallet invariants.
- All previous migrations and Wallet Core are unchanged; only an additive C4a migration. Release keeps `donation-payments.callbacks_enabled=false`, `donation-payments.credits_enabled=false`, and `wallet.enabled_sources.donations=false`. **No production checkout, provider refund API, verified refund notice, or live payments.**

### Deferred — C4b / acceptance

- Provider-backed confirmation of disputes/refunds, scheduled reconciliation and missing-webhook workflow, credential-rotation hardening, fully reviewed payment lifecycle and real merchant/live validation. Windows PHPUnit/Pint/PHPStan/Playwright and isolated MySQL concurrency/upgrade acceptance remain required. Existing dependency advisories also need a separate audit.

## 0.50.2 - 2026-10-17

### Added — Donations C3 (`donations` 1.7.0)

- Persistent **core-owned** `/payments/yoomoney/notify` webhook endpoint, independent of Donations UI/module enablement. Strict form content type, HTTPS production check, exact CSRF exception for signed webhook, throttling, cryptographic HMAC-SHA256 verified with YooMoney credentials; invalid requests fail closed. Default route responds 503 because `config/donation-payments.php` keeps callbacks disabled.
- New additive migration: encrypted durable signed payment receipts, unique provider operation IDs, one settlement record per order and wallet entry, read-only recovery candidate journal. No existing migrations changed.
- Atomic signed callback → immutable order/pricing and payer `withdraw_amount` match → receipt → Core `WalletGateway` credit → order credited status → audit. Exactly-once idempotency at receipt, order settlement and wallet source-key levels. Distinct receiver `amount` preserved for fee reconciliation. No direct Wallet SQL writes or changes to the Wallet financial engine.
- Test/mismatched/cancelled/duplicate/different operation payments held for review, never credited. Retried signed pending notifications are decrypted and reverified, not blindly trusted. Verify/credit rollback together on any audit or wallet failure. History-only recovery identifies missing payment candidates for human review but **cannot prove payer gross** and never credits coins.
- Player/admin displays payment evidence, dedicated error/recovery states and pending transfer prevents cancelling a draft. New negative regression tests for signature, amount, duplicate operations, module disable, cancelled orders, test notices, rollback and replay.
- **Production gates CLOSED**: no player checkout, no real-money sending, `donation-payments.callbacks_enabled=false`, `donation-payments.credits_enabled=false`, `wallet.enabled_sources.donations=false`. No live merchant/API sandbox payment or MySQL parallelism was confirmed. Requires Windows/browser/MySQL acceptance before any activation.

### Deferred to C4

- Real approved merchant end-to-end validation, fees/disputes/refunds, rotating notification secrets, scheduled reconciliation and enablement of the complete checkout lifecycle. No live payment activation in this release.

## 0.50.1 - 2026-10-17

### Added

- Donations C2 (`donations` 1.6.0): allowlisted YooMoney personal-wallet P2P driver, RUB only. `PaymentCheckout` can describe POST form fields; no external checkout is exposed in player routes. The driver keeps payer `withdraw_amount` separate from receiver `amount` and checks signed `sign` with current canonical RFC3986 HMAC-SHA256 (no SHA-1 fallback). Provider test notices remain explicitly non-settling.
- Additive `module_donation_payment_preparations`: one provider per immutable, server-priced player order, stored payment mode/amount/currency/label, own authenticated POST endpoint and fail-closed transactional audit. Existing `module_donation_orders`, Wallet API/ledger, and migrations were not modified.
- Optional read-only YooMoney OAuth `operation-history` label probe for lost callback candidates; it cannot finalize orders or credit coins. New feature/negative and standalone HMAC regression tests.
- **Safety gate:** no real checkout HTML, no live payment entrypoint or webhook route, no automatic settlement/coin credit. Test mode is NOT a provider payment sandbox. User-configured prices in currencies other than RUB remain untouched.

### Deferred (C3/C4)

- Verified notification persistence, amount/label/order matching, provider API reconciliation (including ambiguous/late events), idempotent Wallet credit, protected callbacks during module shutdown, actual test/live transfer, fees and chargeback handling. Run Windows/PHPUnit/Playwright and MySQL concurrency acceptance before any real payment activation.

### Carried forward from working-tree corrections before C2


- Passed the authenticated player to the Donations quote, order history and order detail views. These pages no longer fail with HTTP 500 when the account layout renders. Added coverage for both bundled account themes.
- Deferred fake payment-driver loading until module initialization so PHPUnit can discover the complete suite. Corrected Donations model and contract type declarations, applied Pint formatting, and restored shared administration field-error styles.
- Validated provider currency and credential value shapes before duplicate checks or persistence, with negative coverage for nested values, numeric credential keys and duplicate currencies. Updated the browser module catalogue check to include Donations.
- The working-tree fixes are included in the 0.50.1 full archive without separate release.

**Historical validation of the pre-C2 worktree (not a C2 acceptance):**

- Windows quality gate passed: Pint, PHPStan, installer/updater/package regressions, route cache and 1,054 PHPUnit tests (11 skipped). All 73 browser scenarios passed.
- Dependency audit remains unresolved: Composer reported 19 advisories affecting 7 locked PHP packages on 2026-10-16; npm reported no known vulnerabilities. Passing application tests does not resolve these dependency advisories.

## 0.50.0 - 2026-10-16

### Added

- Donations C1 (`donations` 1.5.0): provider-independent PHP contract for signed callback verification and redirect checkout, immutable payment DTOs, explicit code-controlled driver allowlist and separate provider configurations. No dynamically loaded classes from DB or user input.
- Administrative **Payment providers** pages under Donations with Owner/Admin configuration, Auditor read-only, Editor denied; independent active flags, test/live mode, supported currency selection, encrypted credential storage and generic local readiness rules. No access keys are rendered back to the browser or recorded in audit details.
- Configurations start absent/off. Multiple drivers can be enabled independently as they are installed in future releases; the player's configured currencies/rates stay under their separate existing controls, without automatic conversion. A provider is live-eligible only when enabled in live mode, configured, supports the currency and the Donations rate is also enabled.
- New additive module migration for protected provider settings; existing 0.49.4 orders, core wallets, previous migrations and dependencies are unchanged. Tests for fake multi-provider drivers, encrypted secrets, audit rollback, authorization, invalid currency/mode/driver, missing or corrupt keys, and fail-closed defaults.
- No bundled payment driver, payment creation route, real webhook route, commission settings, automatic credit or status transitions yet. An administrator's test mode selector must not be confused with a verified provider sandbox test. The 0.49.4 unpaid draft flow remains unchanged.
- Full ZIP only by owner request. PHPUnit, PHPStan, Pint, Windows and browser gates require the user's environment for execution.

## 0.49.4 - 2026-10-16

### Added

- Donations B5 (`donations` 1.4.0): authenticated creation of **unpaid test drafts** from the exact server-calculated quote. Private paginated player order history and detail, cancellation, immutable price snapshots and idempotent resubmission; no card, provider, capture, payment or coin credit.
- Independent history routes remain readable when the owner hides new funding requests. Direct draft creation fails closed, whereas owned existing drafts remain viewable and cancellable. When the entire module is disabled its HTTP routes disappear, but database records and Core Wallet remain intact.
- Protected administrative order journal with status/currency/user ID filters, snapshot detail and historical events; Owner/Administrator and read-only Auditor are allowed, Editor denied. Expired drafts are shown as expired even before an explicit persisted expiry transition.
- Additional feature/negative tests and optional isolated multi-process MySQL/MariaDB gate covering 10 identical draft requests, 10 concurrent cancellations, and tariff-update/quote races. No published migration changed, and no new dependencies were introduced.
- The eventual trusted-provider notification processing (including module disable with pending real payments) is reserved for 0.50.x; **do not disable a provider-enabled Donations module with unsettled real orders** until the durable callback lifecycle is implemented and verified.
- Full ZIP only by owner request. Laravel/Windows/browser/MySQL tests require user environment verification.

## 0.49.3 - 2026-10-16

### Added

- Donations B4 (bundled module 1.3.0): durable provider-free payment order drafts with UUID, user-scoped idempotency, exact immutable currency/coin/price/total snapshot, and 30-minute expiry. Stored prices are never recalculated after an administrator changes rates.
- Draft-only state transitions to cancelled or expired, permanent status-event trail and fail-closed CMS audit within the same transaction. User-scoped reads/cancellation; maximum five active drafts per account. Creating drafts stops with the Donations page closed; previously created orders remain retrievable through the service.
- Additive module migration with payment-provider placeholders and protected rollback; negative tests for replay, tampered snapshots, lost audit, foreign ownership, missing/disabled prices and draft limits. No provider, checkout, wallet credit or payment status transitions yet. No new player checkout UI or HTTP routes.
- Core Wallet, existing migrations and dependencies remain unchanged. Full ZIP only by owner request. PHPUnit/Pint/PHPStan/MySQL/Windows/browser gates still require execution before production acceptance.

## 0.49.2 - 2026-10-16

### Added

- Donations B3 (`donations` 1.2.0): authenticated, owner-opt-in player calculator for a whole-number coin quantity and one configured active fiat currency. Estimates are recomputed on the server via the existing exact `DonationRates::quote()` and `DonationMoney`; no browser-provided amount is trusted.
- Only valid enabled rates with matching registry precision appear. Reject missing/forged/disabled currencies, array inputs, invalid quantities and values outside administrator min/max limits. Includes RU/EN feedback, currency-specific fixed precision, empty-state and mobile-friendly form shared by account themes.
- Fixed Donations B2 admin Blade section boundary: the rate and limits controls now render inside the admin content layout. Added regression/negative tests for player previews, disabled tariffs, price tampering and the admin layout.
- Prices are informational; no checkout button, payment orders, providers, webhook handlers, currency exchange, coin grants or changes to Wallet Core. No new migrations or dependencies. Full ZIP only by owner request. Full PHPUnit, Pint, PHPStan, Windows and browser gates remain for user verification.

## 0.49.1 - 2026-10-16

### Added

- Donations B2 (`donations` module 1.1.0): 154-code recognized currency catalogue with per-code exponent/name/symbol. Owner/Administrator can add rates, independently set the cost of **one coin**, enable/disable each currency, and configure global 1–1,000,000 coin quantity limits. Auditor is read-only; Editor denied.
- Exact integer minor-unit price calculation for 0/2/3/4-decimal currencies (e.g. JPY, USD, KWD). No float, no automatic currency conversion, strict positive price and overflow bounds. Editable administrative price preview and protected server-side quote contract for future B3/B4.
- Additive Donations migration with persistent per-currency rates and coin limits, atomic settings updates with fail-closed CMS audit, validation of role, currency code, price precision, quantity and stale registry precision. Tests cover successful and rejected operations, tampering and rollback.
- No real checkout, payment order, provider webhook or coin credit yet. No changes to Wallet Core, previously published migrations, composer.lock, login/game databases or runtime uploads. Delivered full ZIP only per owner preference. Laravel/PHPUnit/Windows/browser/MySQL tests require user acceptance.

## 0.49.0 - 2026-10-15

### Added

- Donations B1: independent bundled `donations` module v1.0.0 with scoped player/admin routes, module migration and explicit Owner/Administrator/Auditor/Editor authorization. The account entry starts hidden and is enabled only by a separately audited setting; module disable hides its routes without touching Core Wallet.
- Safe, read-only player landing renders current coin balance via existing WalletAccountReadService. There are deliberately no payment buttons, fiat currencies/prices, payment orders, provider credentials, webhooks or wallet credits.
- Module-scoped RU/EN translations, user/admin views, module documentation, tests for permissions, visibility, safe module disabling and audit rollback. Module setting updates and audit share a database transaction.
- Core Wallet A1–A7, user balances and old migrations are unchanged. Full release only, by owner preference. User reported all Wallet quality gates green in their updated 0.48.6 source archive; B1 gates require their own verification.

## 0.48.6 - 2026-10-15

### Added

- Wallet Core A7: authenticated Owner/Administrator can block and unblock coin spending on a CMS user's wallet from the existing Wallets administration page. Explicit confirmation, 3–190 character reason, and unique status-operation UUID are required. Auditor remains read-only; Editor and guests are denied.
- Status changes lock the CMS user and wallet rows in the same order as the financial transaction engine. Debit (including future Shop) is prohibited while blocked, credit remains allowed; status changes never change coin balances or financial ledger entries. Unblocking fails closed if the full wallet journal does not reconcile.
- Permanent `wallet_status_events` table with unique operation key/fingerprint, before/after status, administrator, reason and time. The wallet status history is visible only to authorized administration roles and never deleted by ordinary audit-log retention. Existing A1 migration remains unchanged. Status history has restrict-on-delete foreign keys, and its new migration refuses data-destroying rollback.
- A status change and standard CMS audit record are committed together; failed audits roll back everything. Exact repeated status requests do not repeat an event; key reuse for another user/actor/reason/status fails. Added negative regression tests, including access control, corruption, replay, history retention, validation and rollback on audit failure.
- Core Wallet is functionally complete in source, but Windows quality gates, PHPUnit/PHPStan/Pint, real MySQL/MariaDB concurrency, browser tests and live upgrade paths still require verification before calling this version stable. Donations, fiat prices, payment providers and Shop are not yet integrated.

## 0.48.5 - 2026-10-15

### Added

- Core Wallet A6: administrator Wallets page under Users for Owner/Administrator/Auditor, paginated CMS user search by name/email/id, wallet balance and blocked status, per-user ledger history (30 per page) and a direct link from user details. Missing wallets appear as zero without inserting a financial record.
- Owner/Administrator manual credit and debit via the existing `WalletGateway` only. Typed integer values are checked without float conversion; request requires a descriptive reason, an explicit confirmation checkbox, and a unique UUID form key (reused verbatim on browser resubmission). The engine prevents negative/overflow balances, stores actor identity, and requires a transactional audit entry.
- Only admins with `wallet.manage` can POST; the read-only Auditor cannot submit, and Editor/guest cannot view. The permission is enforced both at route middleware and again by `CoreWalletGateway` immediately before mutation. No new Livewire action or direct wallet-table write is exposed.
- Admin UI does not expose wallet source keys, request fingerprints or user game connection secrets. Transactions remain exclusively in the CMS database. New feature tests cover permissions, idempotency, input validation, debit failure, corrupted balances, and isolation between players.
- No wallet blocks/unblocks yet (A7), Donations integration, game-server changes, dependencies, rewritten migrations, or runtime writes in release ZIP. PHPUnit/Pint/PHPStan/Windows/MySQL gates require user environment verification.

## 0.48.4 - 2026-10-15

### Added

- Core Wallet A5: authenticated/localized `/account/wallet` player page with available balance, custom coin icon, read-only 20-entry paginated private history, empty state and spending-blocked notice. A missing wallet safely appears as zero without creating a financial record.
- Both bundled account themes now show the real wallet balance/icon in the topbar and profile dropdown and link to the wallet in the sidebar. Shared responsive wallet styles are maintained in `public/assets/account/css/components.css`. Layouts deliberately do not persist the topbar across Livewire navigation, so displayed balances do not stay stale.
- Account-facing views reveal no internal transaction reference, idempotency key, UUID, admin actor, reason, other user's history or payment details. A server-side controller always scopes entries to the authenticated user, with no user/wallet ID route parameter. No wallet mutation endpoints or payment controls were added.
- Account theme versions: Kaev Aurelia Account `1.6.8`, L2 Obsidian Luxury `1.6.6`; both require CMS `0.48.4`. Feature tests cover authentication, ownership, confidential ledger fields, locale, empty/history and both themes; account navigation regression reflects the non-persistent balance header.
- No changes to A1 wallet migration, balances/ledger engine, existing modules, composer lock, game databases or uploads. Full ZIP only (by owner request); Laravel/Windows/browser/MySQL acceptance is pending.

## 0.48.3 - 2026-10-15

### Added

- Core Wallet A4: administrator page `Settings > Wallet appearance`, using existing CMS settings and role authorization. RU/EN (and enabled language) three plural forms for virtual coins, optional short label, safe default labels, and localized quantity rendering in `WalletAppearanceSettings` for later A5 user views.
- Optional owner-uploaded coin icon (PNG/WebP up to 1 MiB and 1024×1024 pixels). Existing `SettingsImageStorage` validates real image type, extensions, dimensions and safe path; stored under preserved `public/uploads/settings/wallet-coin/`. Invalid uploads cannot mutate settings. Replacements delete only the previous managed icon after settings persist; removing restores the built-in default.
- Role-aware settings navigation: Owner/Administrator edit, Auditor views only, Editor/guests have no access. Security/feature tests cover permissions, image spoofing, invalid forms, icon replacement and path traversal; EN/RU documentation updated.
- No new wallet balance migrations, monetary prices or provider integration. No dependencies, GameServer or module/theme edits. Historical Core Wallet operations are unchanged.
- Full ZIP only; no patch or cumulative artifact (by owner preference). PHP/JSON syntax and archive checks are local; full PHPUnit/Windows/browser/MySQL testing remains pending.

## 0.48.2 - 2026-10-15

### Added

- Core Wallet A3: official server-side `WalletGateway` interface and `CoreWalletGateway` adapter; source allowlist with explicit installation flags (all disabled until integrated). Paid Donations and Shop are **not** enabled by this release.
- Account-owner-scoped Shop debit, explicit authenticated administrator manual adjustments with required reason; new `wallet.view` / `wallet.manage` role permissions. Owner/Administrator can manage, Auditor can view, Editor has no access; no new public HTTP/Livewire wallet mutation endpoints.
- Fail-closed CMS audit recording within the same DB transaction as wallet balance and ledger. On audit persistence failure the mutation rolls back. Idempotent replay leaves no extra audit entry.
- Read-only full historical reconciliation (`kaevcms:wallets-reconcile`, optional `--user` / `--limit`), including per-entry continuity and arithmetic, overflow, invalid directions and current balance matching. Never repairs or deletes ledger records.
- Regression tests for authorization, replay, audit failure rollback, and tampered historical ledger entries; EN/RU wallet documentation and audit labels updated.
- No migrations, dependency changes, GameServer operations, payment provider or theme/module changes. Cumulative baseline remains 0.42.4. Windows/Playwright and MySQL concurrency tests require external execution.

## 0.48.1 - 2026-10-15

### Added

- Implemented Core Wallet A2: an internal atomic credit/debit engine on the CMS database with a single per-user wallet, positive integer coin amounts, transactional balance + append-only journal writes, before/after balance snapshots, and creation on demand for new users.
- Added strict source-scoped idempotency (replays return the original entry; conflicting payloads/users fail), balance row locks, three deadlock attempts, insufficient-funds and blocked-wallet checks, PHP integer overflow prevention, and fail-closed detection of balance/journal drift.
- Added negative and rollback regressions, plus an optional isolated MySQL/MariaDB multi-process concurrency runner for actual row-locking and duplicate-credit verification. The runner requires an explicitly designated disposable testing database.
- Updated EN/RU wallet docs. This remains an internal service only; module-facing authorization/API, player/admin UI, fiat pricing, and payment providers are future iterations (A3+).
- No database migration edits, Composer/npm dependency changes, or bundled module/theme behavior changes. Cumulative baseline remains 0.42.4.

## 0.48.0 - 2026-10-15

### Added

- Began Core Wallet A1 with one wallet per CMS user, a zero starting balance, and an operation-ledger schema featuring globally unique operation UUIDs, source-scoped idempotency keys, request fingerprints, unsigned coin amounts and before/after balance snapshots.
- Added a migration that provisions zero-balance wallets for existing site users without touching L2JMobius/RUSaCis databases; wallet records and history use restrict-on-delete foreign keys. Rollback refuses to drop funded wallets or an existing transaction history.
- Added guarded Wallet/WalletEntry models, a read-only relation from User, schema/upgrade regression tests, and bilingual wallet documentation. This is preparation only: credit/debit engine, UI, fiat pricing, and payment integration are not enabled in A1.
- No Composer/npm lock changes or bundled module/theme behavior changes. Cumulative baseline remains 0.42.4.

## 0.47.48 - 2026-10-15

### Changed

- Refined Aurelia buttons, typography and footer, added locally hosted fonts and floating golden hero particles with reduced-motion support. The footer preserves navigation and account actions; its home shortcut now uses a house icon. Simplified the home news heading.
- Stabilized the public header when navigating between pages of different heights and limited transition animation to page content.
- Unified the Aurelia account typography scale across pages and modules, aligned account action buttons with the public theme, and simplified the server-bound promo reward hint in Russian and English.
- Updated bundled public Aurelia to 1.0.12, account Aurelia to 1.6.7 and Promo Codes to 1.4.2. Added particle, navigation and footer regressions during this release cycle.
- No migrations or dependency changes. Cumulative baseline remains 0.42.4. Local live-preview tooling remains outside release artifacts.

## 0.47.47 - 2026-10-15

### Fixed

- Restored content editor dialog padding after nested forms were replaced by div wrappers. Link and image dialogs now use shared administration fields, primary action buttons and theme-aware surfaces, with bounded height on small screens. News and page editors share the fix.
- Added real-browser regressions for desktop light and mobile dark dialog layout, focus, safe link application, unsafe URL rejection and cancellation.
- Updated module catalog browser assertions to verify the introductory text removed in 0.47.46 stays absent.
- No migrations, dependency or bundled module/theme behavior changes. Cumulative baseline remains 0.42.4.

## 0.47.46 - 2026-10-15

### Changed

- Removed the module lifecycle information block and the trusted-module sentence from the module catalog heading in Russian and English. Module operations, migrations and validation are unchanged.
- No dependency or bundled module/theme behavior changes. Cumulative baseline remains 0.42.4.

## 0.47.45 - 2026-10-15

### Changed

- Removed SSH source-synchronization scripts, their documentation and dedicated CI checks. Local preview setup stays outside release artifacts.
- Preserved Windows quality fixes and all VDS deployment-owner and theme permission repairs. No application, migration, dependency or bundled module/theme behavior changes. Cumulative baseline remains 0.42.4.

## 0.47.44 - 2026-10-15

### Fixed

- Completed VDS deployment-owner permission repairs for integrations and .github. Switching from root to a regular deployment owner no longer leaves these cumulative-update targets unwritable.
- Added a regression deriving required code roots from release-files.json, in addition to theme-manifest access checks, so future required source trees cannot silently be omitted.
- No migrations, dependency or bundled module/theme behavior changes. VDS agent contract remains 3; cumulative baseline remains 0.42.4.

## 0.47.43 - 2026-10-15

### Added

- Added SSH source synchronization for a separate development VM with initial synchronization, save polling, source-file deletion, PHP syntax checks, private staging and source/web-group permissions. Environment files, uploads, storage, migrations, dependency locks and release metadata are excluded.
- Added Windows source-selection regressions, Linux apply/unsafe-path/runtime-protection regressions and bilingual live-development documentation. No additional dependencies are required.

### Fixed

- Included both themes and account-themes in VDS deployment-owner permission repairs. The agent installer verifies theme-manifest access for the deployment account and PHP-FPM before registration, preventing fallback-theme failures after switching from root to a regular deployment owner.
- No migrations, dependency updates or bundled module/theme behavior changes. VDS agent contract remains 3; rerun the installer when changing the project owner. Cumulative baseline remains 0.42.4.

## 0.47.42 - 2026-10-15

### Fixed

- Preserved LF line endings for composer.lock, CSS, JavaScript, shell scripts and PHPStan configuration in Git checkouts. Windows autocrlf no longer changes dependency fingerprints or breaks multiline administration stylesheet contracts; PowerShell scripts retain CRLF.
- Removed process environment variables using an explicit null string so Windows PowerShell 5.1 and PowerShell 7 share the same cleanup behavior on newer .NET runtimes. Added regressions for null, empty and repeated removal and restoration of an initially absent Composer network policy.
- Added a release-source line-ending regression and a Windows CI matrix covering both PowerShell editions. Ignored generated release, PHPStan and isolated browser-test outputs.
- No migrations, dependency updates, bundled module/theme behavior changes or VDS update-agent contract changes. Cumulative baseline remains 0.42.4.

## 0.47.41 - 2026-10-15

### Fixed

- Corrected RUSaCis castle capabilities for the actual SQL table without a name column; castle names now come from the driver-owned ID catalogue with RU/EN labels.
- Derived character hero status from current played/active Olympiad records and unexpired herountil, and excluded previous-period heroes from public statistics. Applied cabinet character limits inside SQL queries.
- Split Mobius and RUSaCis LoginServer operations behind a common driver contract, preserving their password algorithms and SQL schemas. Rejected incompatible persisted credentials before external access, prevented driver changes with linked accounts and revalidated driver pairings after locking registration servers.
- Added original RUSaCis SQL fixtures and regressions for account operations, hero periods, temporary hero expiry, castle ownership, query limits and driver changes. Corrected stale driver expectations, semantic navigation assertions and browser interactions with accessible controls without weakening checks.
- No database migrations, Composer/npm dependency changes or game database schema modifications. Cumulative baseline remains 0.42.4. Actual RUSaCis BCrypt interoperability was verified against the supplied JAR; game-client login and live server smoke testing remain required before declaring the integration stable.

## 0.47.40 RC - 2026-10-14

### Added

- Implemented a separate RUSaCis GameServer driver with required `characters`/`clan_data` schema validation and optional `heroes`/`castle` capabilities. Mobius GameServer and LoginServer code paths remain unchanged.
- Added RUSaCis character listings for linked accounts (including `obj_Id`, nullable `deletetime`, classes, clan, online state and hero flags), level/PvP/PK/play-time rankings, active heroes, castle owners and the online player counter.
- Integrated RUSaCis with existing public statistics, admin external database diagnostics, monitoring and account directory. Driver pairings remain restricted to matching RUSaCis LoginServer/GameServer.
- Left RUSaCis character rescue disabled: SQL column existence alone does not confirm `lastAccess` time units and safe movement semantics. No writes to `items`/`items_delayed` or changes to `kaev_reward_queue`.
- Added schema, filtering, capability, account mapping and mixed-driver PHPUnit regressions plus a browser driver-option regression. No migrations, bundled theme/module changes or dependency updates.
- RC requires live RUSaCis GameServer verification and user Windows quality/browser gates before being considered stable.

## 0.47.39 - 2026-10-14

### Added

- Enabled RUSaCis LoginServer schema checks for accounts(login, password, last_active, access_level, last_server) with account lookup, creation, and password change capabilities.
- Introduced RUSaCis BCrypt $2a$ password encoding while retaining the original Mobius SHA-1 + Base64 encoding and login/account schema handling without changes.
- Preserved encrypted pending account proof across retries rather than regenerating a new salted hash; inserted the proof only after locking the real LoginServer driver. RUSaCis lacks the external email column, which is still stored only in KaevCMS.
- Made RUSaCis GameServer entries available as a registration target when linked to RUSaCis LoginServer; GameServer characters, ratings and rescue remain disabled until the next stage. Rejected mismatched Mobius/RUSaCis account creation pairings.
- Added driver/schema/BCrypt, mixed-driver and idempotent pending retry regressions. No migrations, public themes, account themes, modules or VDS update-agent changes. RUSaCis Java LoginServer compatibility must be confirmed with a real test account prior to production rollout.

## 0.47.38 — Navigation footer and icon polish (2026-10-14)

- Custom footer links now use the same typography, spacing and standard anchor styling as built-in footer entries in both public themes; removed pill-style social button treatment from these links.
- Replaced the navigation admin list placeholder arrow with an accessible, theme-aware SVG link icon. External favicon fetching is deliberately avoided because admin Content-Security-Policy restricts external images; no remote trackers or CSP changes.
- Bumped bundled public theme versions: L2 Dark Classic `0.8.4`, Kaev Aurelia `1.0.11`, and updated stale hardcoded theme-version test expectations.
- Added PHPUnit and Playwright regressions for footer consistency and admin preview, without changing navigation records, schemas, public URLs or unrelated modules/account themes.

## 0.47.37 — Custom public navigation links (2026-10-14)

- New **Content → Navigation** admin section: manage localized RU/EN (and other enabled languages) links to a site-relative `/forum/` path or an external `http(s)` address, with header/footer visibility, order, active status and new-tab controls.
- Both public themes show saved navigation links, while built-in CMS pages remain managed separately. Removed nonfunctional social-link placeholders from the public footers.
- Strict URL validation rejects protocol-relative, unsafe-scheme, control-character and credential-bearing addresses. Public rendering also checks stored URLs and handles not-yet-migrated installations.
- Added audit actions, RBAC via existing content permissions, feature/negative regression coverage and browser flow.
- Public theme manifests: L2 Dark Classic `0.8.3`; Kaev Aurelia `1.0.10`. No dependency changes or runtime upload-path changes.

## 0.47.36 - 2026-08-09

### Fixed

- Replaced the administrator self-profile middleware bypass with a dedicated `admin.account.profile` surface protected by `ProfileManage`; editors can update only their own name/email/password and can no longer open the administrator-management route without `AdministratorsView`.
- Kept administrator role/status/count management inside the protected Administrators section while preserving owner/administrator management and Auditor read-only access.
- Suppressed the temporary maintenance recovery URL when the CLI updater runs with `--yes`, avoiding secret output in non-interactive logs while retaining the URL for manual interactive recovery. The VDS update agent already used a separate command and did not print this secret.
- Replaced the fake `whereRaw('1 = 0')` Web Inventory query with an explicit empty `LengthAwarePaginator` when no GameServer is selected.

### Changed

- Consolidated shared raster MIME mapping, safe upload-path normalization, UUID storage, public-path helpers and empty-directory cleanup into `PublicImageStorage`, reused by News, Pages and Settings image services without changing their public paths or accepted formats.
- Added regression coverage for editor self-profile isolation, non-interactive CLI recovery output and centralized image-path validation. Database migrations, bundled module/theme versions, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative 0.47.36 package supports updates from 0.42.4 through 0.47.35.

## 0.47.35 - 2026-08-08

### Fixed

- Fixed administrator audit-log category counters in dark mode so inactive badges no longer render light text on a light badge background and remain readable before a filter is selected.
- Added explicit spacing between audit category labels and their count badges, avoiding merged labels such as `Administrators32`.
- Added regression coverage for the scoped dark counter selectors and audit-tab spacing. Public/account themes, database migrations, bundled module/theme versions, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative 0.47.35 package supports updates from 0.42.4 through 0.47.34.

## 0.47.34 - 2026-08-08

### Fixed

- Separated the administrator appearance preference stored on the root `<html>` element from the Light/Dark/System control-button attribute, preventing the document root from being treated as a switcher button or receiving `aria-pressed`.
- Improved administrator header wrapping at narrow desktop/tablet widths and stacked the header controls safely on mobile so notifications, appearance controls and the profile menu cannot widen the document.
- Removed the closed off-canvas sidebar transform transition at the mobile breakpoint to avoid transient horizontal overflow while switching from desktop to mobile widths.
- Updated PHPUnit and Playwright regressions for the dedicated appearance-option attribute and root accessibility contract. Public/account themes, database migrations, bundled module/theme versions, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative 0.47.34 package supports updates from 0.42.4 through 0.47.33.

## 0.47.33 - 2026-08-08

### Added

- Added administrator-only Light, Dark and System appearance modes with a compact switcher in the administration header. The public website and player account themes are not affected.
- Added a dedicated final `appearance.css` layer that overrides the existing administration design tokens and hard-coded legacy surfaces for dark mode while preserving the current light design.
- Added `appearance.js` with pre-render theme application, per-administrator browser persistence and live `prefers-color-scheme` tracking while System mode is selected. Light remains the default for backward-compatible upgrades.
- Added feature, release and Playwright regression coverage for stylesheet order, mode switching, SPA navigation persistence and isolation from public/account themes. Database migrations, bundled module/theme versions, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative 0.47.33 package supports updates from 0.42.4 through 0.47.32.

## 0.47.32 - 2026-08-08

### Fixed

- Sorted `deployment/release-files.json` after the 0.47.31 clipboard-helper and shared pagination additions so the release metadata contract remains deterministic and `ReleaseMetadataTest` accepts the manifest.
- Runtime code, administrator behavior, database migrations, bundled module/theme versions, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative 0.47.32 package supports updates from 0.42.4 through 0.47.31.

## 0.47.31 - 2026-08-08

### Changed

- Consolidated administrator copy-to-clipboard behavior into `public/assets/admin/js/clipboard.js`; system information, system updates and two-factor recovery-code actions now use the same Clipboard API/fallback path instead of maintaining three implementations.
- Added the shared `x-admin.pagination` component and replaced duplicated pagination markup across eight core administration lists, including numbered windows and anchored security-log navigation.
- Kept bundled module pagination views unchanged so this internal cleanup does not raise their standalone KaevCMS compatibility floor or module versions.
- Added regression coverage requiring the shared clipboard helper and pagination component to remain the canonical core implementations. Runtime behavior, database migrations, bundled module/theme versions, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative 0.47.31 package supports updates from 0.42.4 through 0.47.30.

## 0.47.30 - 2026-08-08

### Fixed

- Updated account CSS regression tests for the 0.47.29 shared stylesheet extraction so component selectors are validated in `public/assets/account/css/components.css` instead of being incorrectly required inside each theme-owned `app.css`.
- Removed brittle assertions for historical Kaev Aurelia CSS version comments while retaining direct checks for the avatar, reward-history and operation-modal selectors those comments previously represented.
- No runtime behavior, visual styling, database migration, bundled module/theme version, Composer dependency or VDS update-agent contract change is included. The cumulative 0.47.30 package supports updates from 0.42.4 through 0.47.29.

## 0.47.29 - 2026-08-08

### Changed

- Extracted the shared player-account component layer to `public/assets/account/css/components.css`, covering Web Inventory, avatar/profile surfaces, account settings, operation-result modals and offline character rescue.
- Kept bundled theme palette/decor differences in theme-owned `--account-shared-*` tokens so Kaev Aurelia Account and L2 Obsidian Luxury retain their existing visual treatment while no longer duplicating the shared component rules.
- Added release and browser contracts requiring both bundled account layouts to load the shared stylesheet after their theme stylesheet.
- Updated Kaev Aurelia Account to `1.6.6` and L2 Obsidian Luxury to `1.6.5`; both now declare KaevCMS `0.47.29` as their minimum because their layouts depend on the new shared core stylesheet. Bundled module versions, database migrations, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative 0.47.29 package supports updates from 0.42.4 through 0.47.28.

## 0.47.28 - 2026-08-07

- Aligned the PHPStan level 6 annotations introduced in 0.47.27 with the locked Laravel Pint 1.29.3 rules.
- Imported the concrete Eloquent factory classes used by `HasFactory` generics instead of fully-qualified names inside PHPDoc.
- Removed the superfluous native `mixed` parameter annotation in the character directory while retaining its useful `list<int>` return type.
- Runtime behavior, database migrations, bundled module versions, themes, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative 0.47.28 package supports updates from 0.42.4 through 0.47.27.

## 0.47.27 - 2026-08-07

- Resolved the PHPStan level 6 findings reported after enabling the stricter analysis gate across the application and bundled modules.
- Added explicit iterable value types, Eloquent relation/factory generics and typed diagnostics/form helper contracts without changing runtime behavior.
- Kept the published `2026_07_12_000500_create_game_servers_table.php` migration byte-for-byte unchanged; PHPStan contains one narrowly scoped historical exception for its legacy untyped helper parameter.
- Strengthened the release regression so the PHPStan configuration cannot silently fall back to broad exclusions or `treatPhpDocTypesAsCertain: false`.
- Bundled module versions, database migrations, themes, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative 0.47.27 package supports updates from 0.42.4 through 0.47.26.

## 0.47.26 - 2026-08-07

### Changed

- Raised the project PHPStan static-analysis gate from level 5 to level 6 for the application, routes, database code and all bundled module source directories.
- Extended the release regression so the configured PHPStan level cannot silently fall back below 6 while bundled-module coverage remains required.
- No application runtime behavior, database migration, bundled-module version, theme version, Composer dependency or VDS update-agent contract change is included. The cumulative 0.47.26 package supports updates from 0.42.4 through 0.47.25.

## 0.47.25 - 2026-08-07

### Fixed

- Corrected Laravel Pint formatting in the Support Tickets settings controller introduced while adding PHPStan `view-string` typing in 0.47.24.
- No runtime behavior, module version, migration, theme version, Composer dependency or VDS update-agent contract change is included. The cumulative 0.47.25 package supports updates from 0.42.4 through 0.47.24.

## 0.47.24 - 2026-08-07

### Fixed

- Resolved the PHPStan level 5 findings exposed after bundled module source analysis was enabled in 0.47.23, without weakening `phpstan.neon` or disabling PHPDoc certainty checks.
- Added explicit `view-string` assertions for runtime-registered module view namespaces, corrected defensive Daily Rewards snapshot typing, simplified statically redundant reward checks and removed unnecessary nullsafe relation access where the successful operation contract guarantees a reward grant.
- Fixed Support Tickets numeric ticket-number search to use an explicit primary-key OR condition instead of the unavailable `orWhereKey()` builder call, with a regression covering `SUP-xxxxxx` searches.
- Added complete Eloquent property metadata for Support Ticket settings and simplified role notification filtering with an exhaustive enum match.
- Updated bundled Daily Rewards to `1.3.2`, Promo Codes to `1.4.1` and Support Tickets to `1.7.2`. No module migration, theme version, Composer dependency or VDS update-agent contract change is included. The cumulative 0.47.24 package supports updates from 0.42.4 through 0.47.23.

## 0.47.23 - 2026-08-07

### Changed

- Extended the existing PHPStan level 5 analysis to all bundled module source directories: Daily Rewards, Promo Codes and Support Tickets.
- Added a release regression that derives every bundled module source path from its `module.json` autoload contract and requires that path to remain present in `phpstan.neon`, preventing future bundled modules from silently escaping static analysis.
- No application runtime behavior, database migration, bundled-module version, theme version, Composer dependency or VDS update-agent contract change is included. The cumulative 0.47.23 package supports updates from 0.42.4 through 0.47.22.

## 0.47.22 - 2026-08-07

### Fixed

- Fixed the Windows update workflow removing `public/install` before PHPUnit release checks, which caused `AdminNotificationCenterTest`, `ReleaseMetadataTest`, `WebInstallerReleaseTest` and `WebUpdaterReleaseTest` to fail during an otherwise valid update.
- Windows local development updates now preserve the public installer source required by the release quality gates; production Web Update packages still remove `/install` on installed VDS/shared-hosting sites.
- Added `public/install/index.php` as an exceptional repair file so installations interrupted by the 0.47.21 Windows test failure restore the missing entry point automatically when the hotfix patch is extracted.
- No application runtime behavior, database migration, bundled-module version, theme version, Composer dependency or VDS update-agent contract change is included. The cumulative 0.47.22 package supports updates from 0.42.4 through 0.47.21.

## 0.47.21 - 2026-08-07

### Changed

- Reduced `GameServerManager` by moving form validation/schema responsibilities into `GameServerFormSchema` and editor load/reset state into `GameServerFormState`.
- Preserved the existing Livewire public properties/actions, GameServer services, database schema, L2JMobius driver contracts and administration UI behavior.
- No database migration, bundled-module version, theme version, Composer dependency or VDS update-agent contract change is included. The cumulative 0.47.21 package supports updates from 0.42.4 through 0.47.20.

## 0.47.20 - 2026-08-07

### Changed

- Strengthened the unified release builder so target and previous apply-script metadata is verified against the actual source tree and direct previous full archive before any release artifact is accepted.
- Added cross-tree SHA256 validation for both current and previous `composer.lock` metadata, preventing stale dependency hashes from silently entering a release.
- Made `repair_files` data-driven in `ReleaseMetadataTest`: the test now validates structure and file existence instead of hard-coding the current list. The builder rejects repair entries that are already ordinary changed files.
- Added release-builder regression fixtures for mismatched apply hashes, Composer hashes, apply-script metadata and stale repair entries. Application runtime behavior, database migrations, modules, themes and VDS update-agent contract 3 are unchanged.
- The cumulative 0.47.20 package supports updates from 0.42.4 through 0.47.19.

## 0.47.19 - 2026-08-02

### Fixed

- Corrected the system-update progress regression to create and clean up a real temporary log fixture before asserting that the details panel is visible.
- Changed the update status response test to verify the effective `no-store` and `private` cache-control directives after administration middleware normalization instead of requiring one exact header string and order.
- Fixed the remaining Laravel Pint `single_quote` violation in `WebUpdaterReleaseTest`.
- No application runtime file, database migration, Composer dependency, bundled-module version, theme version or VDS update-agent contract change is included. The cumulative 0.47.19 package supports updates from 0.42.4 through 0.47.18.

## 0.47.18 - 2026-08-02

### Fixed

- Removed shared generic HTTP throttles from update upload/apply/recovery and mail delivery-mode probes; duplicate operations are handled by update/probe state instead of returning a raw HTTP 429 page.
- Added an immediate system-update progress dialog, duplicate-submit protection, VDS status polling and automatic state recovery after reloading the update page.
- Collapsed the update log preview behind “Show details” and changed obsolete-path logging to list only paths that were actually removed plus one checked/removed/already-absent summary.
- Forced VDS update-agent register, run and status commands, including status JSON messages, to stable English output without changing localized administration messages or agent contract 3.
- Updated bundled Support Tickets to `1.7.1`; its administrator search field now uses the shared `x-admin.field` design and browser regression coverage.
- No database migration, Composer dependency, bundled-theme version or VDS update-agent contract change is included. The cumulative 0.47.18 package supports updates from 0.42.4 through 0.47.17.

## 0.47.17 - 2026-08-02

### Fixed

- Fixed the Pint `single_quote` failure in `tests/Feature/ReleaseMetadataTest.php` introduced by the Promo Codes archive regression assertions.
- Kept Promo Codes 1.4.0, Support Tickets 1.7.0 and all application runtime behavior unchanged.
- No database migration, Composer dependency, bundled-module version, theme version or VDS update-agent contract change is included. The cumulative 0.47.17 package supports updates from 0.42.4 through 0.47.16.

## 0.47.16 - 2026-08-02

### Added

- Updated bundled Promo Codes to `1.4.0` with separate Current, Archive and All views, archive restoration and explicit archive counts.
- Updated bundled Support Tickets to `1.7.0` with Owner-only deletion of individual closed, unprotected tickets together with their messages, internal notes and revision history.

### Changed

- Promo codes with no activations are now permanently deleted, while used promo codes are disabled and soft-deleted into the archive so activation and granted-reward history remains intact.
- Restored promo codes remain disabled to prevent accidental reactivation and every archive, restore and permanent-delete action is audited.
- Promo-code deletion now locks the row shared with activation processing, preventing a concurrent activation from racing a permanent delete.
- Open or retention-protected support tickets fail closed during manual deletion; Administrator, Editor and Auditor roles do not receive deletion access. Existing scheduled and manual retention cleanup remains unchanged.
- Added positive and negative regressions for promo-code archive/restore, unused-code deletion, ticket deletion cascades, role access, open-ticket rejection and retention-protection rejection.
- No database migration, Composer dependency, bundled-theme version or VDS update-agent contract change is included. The cumulative 0.47.16 package supports updates from 0.42.4 through 0.47.15.

## 0.47.15 - 2026-08-02

### Changed

- Replaced player-facing GameServer queue terminology in Web Inventory with concise transfer wording and the completed status `Transferred` / `Передано`.
- Added explicit validation messages for character, reward selection, server and request-form failures so Laravel no longer exposes internal field names such as `character id` or `inventory item ids`.
- Made unavailable reward delivery fail closed with one generic player message while preserving exact `kaev_reward_queue` diagnostics and remediation in the administration reward journal.
- Updated both bundled account themes to describe reward transfer without mentioning KaevCMS internals or the external queue implementation; Kaev Aurelia Account is now `1.6.5` and L2 Obsidian Luxury is now `1.6.4`.
- Added regressions for missing queue capability, player-safe validation text and bundled-template boundary enforcement. No migration, Composer dependency, bundled-module version or VDS update-agent contract changed. The cumulative 0.47.15 package supports updates from 0.42.4 through 0.47.14.

## 0.47.14 - 2026-08-02

### Fixed

- Fixed the PHPStan `if.alwaysFalse` regression in `UpdatePackageInspector` introduced by the repeated staging symbolic-link race-condition guard.
- Preserved both runtime checks before and after staging-directory creation by routing them through a dedicated assertion method instead of removing the second security check.
- Added a Unix regression confirming that a symbolic-link staging root is rejected.
- Kept Web Update behavior and VDS update-agent contract version 3 unchanged; no agent reinstall, migration, Composer dependency, bundled-module version, or theme version change is required. The cumulative 0.47.14 package supports updates from 0.42.4 through 0.47.13.

## 0.47.13 - 2026-08-02

### Fixed

- Fixed Web Update package inspection on root-owned VDS installations where `storage/app/kaevcms/updates/staging` correctly belongs to the deployment owner and PHP-FPM group with mode `2770`.
- Stopped requiring PHP-FPM to run `chmod` on an existing secure staging directory that is already writable through its group; Linux permits only the owner or root to change that mode.
- Preserved staging security checks: symbolic links are rejected, newly created or world-accessible directories are normalized when possible, and unsafe or non-writable directories still fail closed.
- Added a regression that forces `chmod` failure and confirms an existing secure group-writable staging directory remains usable.
- Kept VDS update-agent contract version 3 unchanged; no agent reinstall, migration, Composer dependency, bundled-module version, or theme version change is required. The cumulative 0.47.13 package supports updates from 0.42.4 through 0.47.12.

## 0.47.12 - 2026-08-02

### Fixed

- Fixed Pint formatting in the VDS update filesystem transaction and Web Updater release regression.
- Resolved PHPStan always-true type checks without removing runtime validation: malformed deployment user, UID, web group or GID metadata still fails before any application file is changed.
- Added regressions for root-owned and regular-user-owned systemd service identities, mandatory reinstall of legacy agent v2 registrations, a clean no-request agent run, and malformed deployment identity metadata.
- Kept VDS update-agent contract version 3 and the existing systemd/ownership model unchanged; an already ready v3 agent does not require reinstallation.
- No database migration, Composer dependency, bundled-module version, or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.12 package supports direct updates from 0.42.4 through 0.47.11.

## 0.47.11 - 2026-08-02

### Fixed

- Moved GNU `find` global `-mindepth` options before path expressions in the VDS update-agent installer, removing the warnings shown during installation or reinstallation.
- Added a release regression that requires the warning-free option order in both protected public-path and KaevCMS runtime scans.
- Kept agent contract version 3 and the existing root/regular-user ownership model unchanged; no agent reinstall is required solely for this maintenance update.
- No database migration, Composer dependency, bundled-module version, or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.11 package supports direct updates from 0.42.4 through 0.47.10.

## 0.47.10 - 2026-08-02

### Fixed

- Reworked the VDS agent installer into a single-command flow that requests sudo automatically when required and works from both root and regular administrative shells.
- Added automatic project-owner and PHP-FPM identity detection, with explicit `--project-user`, `--web-user`, and `--web-group` overrides for non-standard servers.
- Repaired application ownership and group-readable modes during agent installation while keeping PHP-FPM without source-code write access.
- Repaired Web Update request, package, staging, cache, log, upload, and KaevCMS runtime paths with protected group-write inheritance.
- Normalized ownership, group, and modes for every application file replaced by the VDS agent and for files restored during rollback, preventing selective HTTP 500 permission failures.
- Added access probes for PHP-FPM reads and Web Update writes, agent contract version 3 metadata, RU/EN documentation, and permission regressions.
- No database migration, Composer dependency, bundled-module version, or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.10 package supports direct updates from 0.42.4 through 0.47.9.

## 0.47.9 - 2026-08-02

### Fixed

- Added an isolated test-only `APP_KEY` to PHPUnit so clean release trees do not depend on a server `.env` key.
- Synchronized shared administration tab expectations and the visual active-tab contract with the current generated class order and computed `display: flex` value.
- Made the isolated Support Tickets UI test explicitly enable and boot its module before checking module routes.
- Updated CLI updater and VDS agent tests for the protected group-write contract introduced in 0.47.8.
- Made the browser runner create and remove a temporary `.env` marker only when a clean release tree has no physical file.
- Added a regression for the mail category on the administration audit log.
- Application runtime behavior, migrations, Composer dependencies, bundled-module versions and theme versions are unchanged. The cumulative update line remains based on `0.42.4`; the 0.47.9 package supports direct updates from 0.42.4 through 0.47.8.

## 0.47.8 - 2026-08-02

### Fixed

- Fixed VDS browser-update uploads on root-owned installations where `storage/app/kaevcms/updates/packages` or `staging` had been created with owner-only permissions.
- Updated the VDS agent installer to create and repair request, package and staging directories as deployment-owner/PHP-FPM-group runtime paths with setgid group inheritance, without granting PHP-FPM write access to application source files.
- Changed the CLI updater to preserve protected group access (`2770` directories and `0660` package files) instead of locking the Web Updater out with `0700`/`0600` permissions.
- Bumped the local agent contract to version 2. Older registrations are shown as needing repair and can be fixed by rerunning the existing installer command.
- Added clear upload/staging permission errors, RU/EN documentation and PHPUnit/release regressions for the shared runtime-directory contract.
- No migration, Composer dependency, bundled-module version or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.8 package supports direct updates from 0.42.4 through 0.47.7.

## 0.47.7 - 2026-08-02

### Changed

- Added shared Blade components for administration cards, card headings, buttons, top-level and contextual tabs, and filter bars while preserving the established light visual style and existing compatibility classes.
- Migrated Settings, Mail, Audit, Users, Daily Rewards and Support Tickets reference screens to the shared UI contracts; removed duplicate legacy styling for audit tabs and user filters.
- Fixed the Support Tickets editor-permission regression introduced by the form unification: toggle titles and hints render as separate block lines again, so dependent controls remain readable and the Playwright contract passes.
- Added PHPUnit and Playwright regressions for the shared card/button/tab/filter system, responsive layouts and the editor-permission label contract.
- No migration, Composer dependency, bundled-module version or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.7 package supports direct updates from 0.42.4 through 0.47.6.

## 0.47.6 - 2026-08-02

- Added shared `x-admin.field` and `x-admin.toggle` Blade components for standard administration forms.
- Standardized field height, thin borders, focus states, hints and validation errors around the established Security settings appearance.
- Replaced legacy publication and module checkboxes with the modern administration switch used by Registration and Game Accounts.
- Unified validation error classes across system settings, GameServer/LoginServer management and bundled modules, including accessible `role="alert"` output and `aria-invalid` on migrated fields.
- Removed obsolete duplicate field and switch CSS while retaining visual compatibility for existing `form-group` markup.
- Added PHPUnit and Playwright regressions for shared form components, desktop dimensions, mobile overflow and switch interaction.
- No database migration, Composer dependency or bundled-theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.6 package supports direct updates from 0.42.4 through 0.47.5.

## 0.47.5 - 2026-08-02

### Fixed

- Added the missing literal `GameServer` key to RU/EN JSON localization so the compact external-database diagnostic card no longer depends on fallback rendering.
- Updated the Web Updater feature expectation to the final compact trusted-package warning introduced in 0.47.4.
- Updated the APP_KEY Playwright regression to use the stable `system-app-key-card` test id instead of the heading wrapper changed by the compact-card layout.
- Corrected the stale Russian release paragraph in README and synchronized release metadata regressions for this maintenance package.
- No application runtime behavior, migration, Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.5 package supports direct updates from 0.42.4 through 0.47.4.

## 0.47.4 - 2026-08-02

### Changed

- Replaced the large LoginServer and GameServer diagnostic sections on **System information** with three compact cards for APP_KEY, LoginServer and GameServer. The connection cards show only aggregate configured/available counts, current state, last check and a direct settings link.
- Retained safe external-database refresh and support-report diagnostics without showing server names, schema tables, capabilities, database credentials or other verbose details on the page.
- Removed the separate trusted-source warning above the Web Updater upload card. The existing upload description is now one compact red warning that explains official-source use, program-file replacement, pre-install verification and owner responsibility.
- Added PHPUnit and Playwright regressions for aggregate connection states, three-card desktop layout, responsive overflow, hidden detailed server data and the single compact update warning.
- No migration, Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.4 package supports direct updates from 0.42.4 through 0.47.3.

## 0.47.3 - 2026-08-02

### Fixed

- Allowed `deployment/vds/install-update-agent.sh` to install the VDS update agent when the KaevCMS project is intentionally owned by `root` instead of rejecting that deployment model.
- Added an explicit root-privilege warning: a selected update package is applied with root access and must come from a trusted source. PHP-FPM still receives access only to the dedicated request directory.
- Improved PHP-FPM group detection for root-owned projects by falling back to the standard `www-data` group when `storage` is still owned by `root`; non-standard pools can use `KAEVCMS_WEB_GROUP`.
- Documented root and non-root installation, reinstallation and diagnostics in RU/EN and added release regressions for the original root-owner failure.
- No migration, Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.3 package supports direct updates from 0.42.4 through 0.47.2.

## 0.47.2 - 2026-08-02

### Fixed

- Synchronized `ReleaseMetadataTest` with the actual 0.47.2 `release.json` repair contract. The 0.47.1 package still expected the complete 0.47.0 VDS updater file list, while its metadata correctly contained only the two test repairs.
- Kept the correction limited to release metadata verification. No application runtime, database migration, Composer dependency, bundled-module version or theme version changed.
- The cumulative update line remains based on `0.42.4`; the 0.47.2 package supports direct updates from 0.42.4 through 0.47.1.

## 0.47.1 - 2026-08-02

### Fixed

- Removed an extra blank line from `WebUpdaterReleaseTest` so Laravel Pint no longer fails the VDS updater release gate.
- Aligned the exact Playwright assertion with the complete trusted-source warning rendered by the system update page without weakening the warning coverage.
- No runtime behavior, migration, Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.1 package supports direct updates from 0.42.4 through 0.47.0.

## 0.47.0 - 2026-08-02

### Added

- Added a manually installed Ubuntu VDS update agent based on per-installation `systemd.path` and one-shot `systemd.service` units. Verified Web Update requests are queued in protected runtime storage and executed as the project deployment owner without granting PHP-FPM write access to application source files or opening a network port.
- Integrated the agent with the existing Web Updater pipeline: package inspection remains in the administration panel, while the agent rechecks the archive hash and real file permissions before using the established backups, maintenance mode, filesystem transaction, migrations, cache cleanup, queue restart, logging and rollback flow.
- Added agent registration, status and worker Artisan commands, encrypted temporary recovery-secret storage, one-request concurrency protection, atomic request files and recovery-safe status tracking.
- Added clear agent setup, diagnostics and removal instructions in RU/EN VDS and update documentation. Existing installations below 0.47.0 use the CLI Updater once, then install the agent for future browser-started updates.

### Changed

- Added an explicit trusted-source confirmation before any system update can start. The interface explains that update archives can replace program files and that the site owner is responsible for selecting a trusted source.
- Changed Ubuntu/MySQL documentation examples to use `kaevcms_db` for the database and `kaevcms_user` for the database account, including SQL, connection verification and Web Installer fields.
- Added PHPUnit, release-contract and Playwright regressions for agent registration, encrypted queue state, archive revalidation, missing-agent guidance, trusted-source validation, shipped systemd units, documentation and mobile updater layout.
- Added one CMS migration for VDS-agent execution state. No Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.0 package supports direct updates from 0.42.4 through 0.46.5.

## 0.46.5 - 2026-08-01

### Fixed

- Replaced the dashboard disk fill element and CSP-blocked inline width with a native accessible `<progress>` control, preserving normal, warning and danger colors in Chromium, WebKit and Firefox.
- Kept the Kaev Aurelia Account mobile backdrop outside the 286 px sidebar area so blur and pointer handling never cover the open navigation panel.
- Removed case-insensitive duplicate `players` translation keys and tightened `DashboardPlayerOverview` typed-array access for PHPStan.
- Contained footer grids, social links and long content inside mobile viewports for both bundled public themes; Kaev Aurelia now collapses its footer to one column at the existing mobile breakpoint.
- Updated L2 Dark Classic to `0.8.2`, Kaev Aurelia to `1.0.9` and Kaev Aurelia Account to `1.6.4`; added PHPUnit and Playwright regressions for strict-CSP disk rendering, menu/backdrop geometry and public-theme footer overflow.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.5 package supports direct updates from 0.42.4 through 0.46.4.

## 0.46.4 - 2026-08-01

### Added

- Added a compact **Players** card below Storage in the wide dashboard column with registered KaevCMS users, active game accounts, available character totals and the Support Tickets attention badge when that module is enabled and accessible to the administrator.
- Character totals are cached briefly, count only normal non-deleted characters across currently configured game databases and degrade to a partial or unavailable note instead of breaking the dashboard when an external database is offline.

### Fixed

- Replaced the mobile account-menu `html::after` overlay with an explicit shared backdrop component. The backdrop now sits below the sidebar, receives close clicks and owns the blur, while the menu remains sharp, above the overlay and fully interactive in both bundled account themes.
- Updated L2 Obsidian Luxury and Kaev Aurelia Account to `1.6.3` and added PHPUnit and Playwright regressions for dashboard permissions/counts, module-aware support metrics, backdrop layering, direct backdrop closing, menu-link clicks and responsive placement.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.4 package supports direct updates from 0.42.4 through 0.46.3.

## 0.46.3 - 2026-08-01

### Fixed

- Replaced the oversized aggregate online card with compact last-updated text and the existing **Check now** action, while retaining per-GameServer online counts in each server row.
- Moved the GameServer card into the right dashboard column above LoginServer, leaving the storage overview in the wider left column and keeping system operations full-width below.
- Rendered disk usage with a direct inline percentage on the fill element instead of a nested CSS `min()`/`max()` custom-property expression. The filled segment now paints immediately after page load without requiring a browser repaint.
- Added PHPUnit and Playwright regressions for removal of the aggregate counter, per-server online visibility, desktop card placement, compact refresh controls, initial disk-bar width and a visibly distinct fill color.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.3 package supports direct updates from 0.42.4 through 0.46.2.

## 0.46.2 - 2026-08-01

### Added

- Added a **Storage** dashboard card in the wider left column with server-disk totals, used/free capacity, percentage state and a compact administration-style progress bar.
- Added safe KaevCMS database storage details for MySQL, MariaDB and SQLite: engine/version, total size, data, indexes and table count where the active database and hosting permissions expose those values.
- Kept database names, hosts, usernames, passwords, DSNs and SQLite paths out of dashboard output; unavailable hosting statistics degrade to a clear non-fatal message.
- Reused existing dashboard cards, status badges, spacing and typography without adding a chart dependency, migration or bundled-module change.
- Added PHPUnit and Playwright regressions for database engines, restricted statistics, permissions, accessibility, responsive layouts and horizontal overflow.
- The cumulative update line remains based on `0.42.4`; the 0.46.2 package supports direct updates from 0.42.4 through 0.46.1.

## 0.46.1 - 2026-08-01

### Fixed

- Expanded the existing administration switch checkbox over the complete 46×26 control, restoring normal direct pointer clicks while preserving the current visual component, keyboard focus and label behavior.
- Limited notification-settings help-tooltip width below 520 px so hidden absolute tooltip content cannot enlarge the mobile document horizontally.
- Sorted notification service imports in `AppServiceProvider` according to Laravel Pint.
- Added a Playwright regression that verifies the checkbox itself exposes the full clickable target and toggles through real pointer clicks; the existing mobile test continues to guard tooltip overflow.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.1 package supports direct updates from 0.42.4 through 0.46.0.

## 0.46.0 - 2026-08-01

### Added

- Added **Settings → Notifications** with the existing administration switch component for grouped actionable sources: Technical Support, module updates, KaevCMS updates, background tasks, LoginServer availability, GameServer availability, low disk space, leftover installer and critical system diagnostics.
- Added the existing accessible `?` tooltip component beside every source, with short Russian and English explanations that avoid internal migration, queue and Scheduler terminology in the visible labels.
- Added a settings gear to the right of **All / Unread** in the notification dropdown. It is available to roles with settings access, remains responsive on mobile and opens the notification settings tab directly.
- Added configurable automatic cleanup and 30/60/90/180-day retention. Disabling automatic cleanup does not affect the manual `kaevcms:notifications-clean --days=<number>` command.
- Disabled sources no longer create one-time notifications. Recurring problems are resolved in the notification lifecycle without deleting existing list entries, so re-enabling a still-active source creates a fresh event on the next scan while diagnostics continue to show the real state.
- Added PHPUnit and Playwright regressions for saving preferences, access control, grouped Support Ticket events, separate LoginServer/GameServer switches, recurring-problem reactivation, cleanup behavior, tooltip interaction, gear navigation and mobile overflow.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.0 package supports direct updates from 0.42.4 through 0.45.5.

## 0.45.5 - 2026-08-01

### Fixed

- Switched the isolated Playwright rate-limiter store from transient `array` cache to the per-run temporary SQLite database while keeping the ordinary browser cache in memory. Repeated HTTP requests now exercise the real three-diagnostic-downloads-per-minute contract without sharing state across runs.
- Synchronized the runtime cache PHPUnit contract with the isolated database limiter and retained the Windows SQLite cleanup regression.
- Removed unreachable null-coalescing fallbacks from mandatory regex callback matches, resolving the PHPStan `nullCoalesce.offset` findings without changing IPv4/IPv6 redaction behavior.
- Added no migration, dependency, bundled-module or user-facing behavior change. The cumulative update line remains based on `0.42.4`; the 0.45.5 package supports direct updates from 0.42.4 through 0.45.4.

## 0.45.4 - 2026-08-01

### Fixed

- Collapsed identical recent Laravel warning/error signatures in diagnostic packages into one line with occurrence count and first/last timestamps, preventing one repeated problem from flooding `recent-errors.log`.
- Replaced over-broad IP regular expressions with validated IPv4/IPv6 redaction, preserving ISO timestamps and package/operating-system/database version strings while still removing real addresses.
- Replaced the diagnostics route HTTP 429 page with a per-administrator three-downloads-per-minute guard that redirects back and shows a small localized wait message under the download button.
- Removed the duplicate **Manage updates** action from System information because the existing **System updates** tab already provides the same navigation.
- Added PHPUnit and Playwright regressions for signature aggregation, IP-safe timestamps/versions, friendly throttling, and removal of the duplicate update action.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.45.4 package supports direct updates from 0.42.4 through 0.45.3.

## 0.45.3 - 2026-08-01

### Fixed

- Synchronized `ReleaseMetadataTest` with the 0.45.2 `release.json` repair contract. The test still expected the older 0.45.1 notification-center file list, causing the otherwise valid 0.45.2 release metadata gate to fail.
- Kept the correction limited to release metadata verification. No application runtime, database migration, dependency, bundled-module version or user-facing behavior changed.
- The cumulative update line remains based on `0.42.4`; the 0.45.3 package supports direct updates from 0.42.4 through 0.45.2.

## 0.45.2 - 2026-08-01

### Fixed

- Selected the Composer `ClassLoader` that actually owns the KaevCMS core classes when registering module namespaces, instead of mutating an arbitrary first loader such as the PHPStan PHAR loader.
- Corrected nullable notification model metadata and strict PHPStan contracts for diagnostic redaction, notification payload normalization, runtime diagnostics, encryption health and disk-space probes.
- Corrected three PHPUnit regressions: missing notification targets now resolve to no action URL, and literal `$ticket` / `$installerRemoved` source assertions no longer interpolate test variables.
- Stacked the system overview actions below 900 px so the diagnostics page no longer expands a 768 px viewport horizontally.
- Added a regression proving module namespaces are registered on the project Composer loader even when another loader is prepended to the process.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.45.2 package supports direct updates from 0.42.4 through 0.45.1.

## 0.45.1 - 2026-08-01

### Added

- Added a personal administrator notification center in the top panel with a neutral bell, red unread counter capped at `99+`, compact All/Unread filters, severity icons, direct links and responsive desktop/mobile behavior.
- Added per-administrator read state and list cleanup: mark all as read, clear read notifications, and clear all with confirmation. Reading or dismissing an event never changes the underlying diagnostic/server/queue state.
- Added deduplicated actionable sources for new Support Tickets and player replies, bundled-module updates and pending migrations, pending CMS migrations, failed CMS updates, queue/Scheduler problems, unavailable configured LoginServer/GameServer connections, low disk space, a leftover installer and critical encryption diagnostics.
- Added recurring-problem lifecycle tracking so an unresolved failure updates one record without recreating its badge after dismissal, while a resolved problem that later returns creates a new notification.
- Added scheduled scanning every minute and physical retention cleanup, with safe behavior before the notification migration is applied and no notification failure allowed to break the source operation.
- Updated bundled Support Tickets to `1.6.0` for KaevCMS `0.45.1+`; ticket subjects, bodies, player names and email addresses are never copied into notification payloads. No module migration or dependency change was added.
- Added PHPUnit and Playwright regressions for recipient permissions, administrator isolation, idempotency, recurring problem resolution, route allowlists, `99+`, filters, bulk actions, installer detection, ticket privacy, direct navigation, confirmation and responsive integration.
- The cumulative update line remains based on `0.42.4`; the 0.45.1 package supports direct updates from 0.42.4 through 0.45.0.

## 0.45.0 - 2026-08-01

### Added

- Added an administrator diagnostic package under **Settings → System information** with a readable report and separate sanitized snapshots for versions, environment, permissions, CMS and external databases, scheduler, queue, disk space, modules, module/core migrations, recent CMS updates and recent error signatures.
- Added layered secret and personal-data redaction for structured values and text, covering `APP_KEY`, passwords, tokens, authorization data, cookies, database/mail credentials, email addresses, IP addresses, DSNs, credential-bearing URLs and absolute project paths.
- Reduced Laravel warnings and errors to timestamp, severity, exception class and a short SHA-256 fingerprint; raw log messages, `.env`, user rows, database files and complete logs are never copied into the archive.
- Added temporary package cleanup, download auditing, a three-downloads-per-minute limit and explicit access for owners, administrators and trusted auditors with system-information permission.
- Added RU/EN administration documentation plus unit, feature, permission, UI and secret-leakage regression contracts. No database migration, module update or dependency change was added.
- The cumulative update line remains based on `0.42.4`; the 0.45.0 package supports direct updates from 0.42.4 through 0.44.34.

## 0.44.34 - 2026-07-31

### Security

- Removed the public `/install` directory automatically only after the database, owner, release marker and `installed.lock` are created successfully; interrupted or failed installations retain the installer for safe resume.
- Rendered the final installation result in the successful POST response so cleanup can happen before `/install/` becomes unavailable, with an explicit manual fallback when filesystem permissions prevent deletion.
- Added symlink-safe, idempotent recursive cleanup limited to the fixed public installer directory without touching sibling runtime files.
- Kept full releases installable while ensuring cumulative Web Updates never include the installer, and made installed Windows update cleanup remove any leftover `public/install` directory.
- Updated RU/EN installation and shared-hosting documentation plus standalone installer, PHPUnit and Windows workflow regression contracts. No database migration, module update or dependency change was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.34 package supports direct updates from 0.42.4 through 0.44.33.

## 0.44.33 - 2026-07-31

### Fixed

- Started the isolated Playwright HTTP server with `artisan serve --no-reload`, preserving the runner-provided SQLite database, application key, test credentials and rate-limit environment on Windows instead of allowing the reload child process to fall back to the normal `.env`.
- Made the mobile administration backdrop regression use the actual element bounds, avoiding an out-of-range fixed coordinate when a Windows scrollbar reduces the viewport width.
- Corrected Support Tickets hardening regressions so reply text is prepared before access is revoked and exactly one subsequent Livewire action is required to return 403 without creating a message.
- Applied the project Pint formatting to `SafeHtmlSanitizer` and the affected infrastructure/module tests without changing sanitizer behavior or weakening any assertion.
- Added release and runtime contracts for the no-reload browser server. Support Tickets remains at 1.5.2 and no database migration or dependency update was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.33 package supports direct updates from 0.42.4 through 0.44.32.

## 0.44.32 - 2026-07-31

### Fixed

- Stabilized the accessible name of administrator module links when a live attention badge is present; the badge remains an independent polite status region for assistive technologies.
- Kept all LoginServer and GameServer browser fixtures fresh after seeding so the first dashboard visit cannot replace deterministic `17 ms` and `23 ms` diagnostics with unsupported-driver results.
- Removed the unused `Filesystem` dependency from `ModuleRuntime`, resolving the PHPStan `property.onlyWritten` failure without suppressions.
- Corrected Support Tickets hardening tests so guarded user activation and email-verification columns are actually changed before asserting that an open Livewire conversation is forbidden.
- The cumulative update line remains based on `0.42.4`; the 0.44.32 package supports direct updates from 0.42.4 through 0.44.31.

## 0.44.31 - 2026-07-31

- Stopped scheduled server monitoring from logging `GameServer database monitoring failed` for the intentionally incomplete default GameServer placeholder. Missing drivers, LoginServer selection or database credentials now produce a quiet `not_configured` snapshot with zero failures instead of a recurring warning.
- Hardened cumulative updates around the public installer: `public/install` is excluded from payload files and added to the update deletion list, so updating an installed CMS removes a leftover installer and never restores `/install/`.
- Updated bundled Support Tickets to 1.5.2 without a database migration. Its administration badge now remains visually hidden at zero even with the menu badge display rule.
- Restricted support reply, note and edit text areas to vertical resizing with fixed container width, preventing action buttons from being pushed outside the ticket card.
- Replaced the large “Edit message” text action with a compact accessible pencil button in the message header and updated PHPUnit, package-builder and Playwright regressions for all five fixes.
- The cumulative update line remains based on `0.42.4`; the 0.44.31 package supports direct updates from 0.42.4 through 0.44.30.

## 0.44.30 - 2026-07-31

- Added a shared module PSR-4 autoloader used by both runtime boot and database migrations. Pending migrations can now safely reference constants, models or services shipped by the incoming module version without activating its bootstrap or routes first.
- Fixed the Support Tickets 1.5.1 update path from 1.4.2: the unchanged `2026_07_30_230000_seed_support_ticket_settings.php` migration can now load `SupportTicketSettings` and complete normally after the CMS update.
- Replaced the mobile administration menu with an accessible off-canvas drawer: a compact sticky toolbar opens it above the page, while close button, backdrop, Escape and navigation links close it without affecting the desktop sidebar.
- Preserved accordion group state, automatic opening of the active group, the live Support Tickets badge, body scroll locking and reduced-motion behavior inside the new drawer.
- Added PHPUnit and Playwright regressions for module-class autoload during migrations and the complete mobile drawer lifecycle. No database migration, module version or dependency change was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.30 package supports direct updates from 0.42.4 through 0.44.29.

## 0.44.29 - 2026-07-31

- Reworked the administration navigation below 760 px: group headings remain visible, native expand/collapse works on touch devices, the active group opens automatically and nested links are visually separated instead of merging into one horizontal list.
- Stabilized player-account module navigation by removing hover-prefetch from dynamic module links, adding stable module identifiers and strengthening the Daily Rewards browser regression around the real navigation link.
- Kept browser-test server-monitor snapshots fresh for the full suite, preventing the intentionally unsupported test drivers from repeatedly filling the test log with expected database-monitoring warnings.
- Added mobile Playwright and PHPUnit contracts for grouped navigation, module-link routing and the test-only monitoring interval. No database migration, module update or dependency change was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.29 package supports direct updates from 0.42.4 through 0.44.28.

## 0.44.28 - 2026-07-31

- Updated bundled `support-tickets` to 1.5.1 and moved its administration attention badge into a persistent Livewire component: staff replies, closing and reopening refresh it immediately, while 30-second polling discovers tickets changed in other sessions without reloading the page.
- Made `kaevcms:cache-clean` use the configured database-cache connection, table, lock connection and lock table instead of hard-coded defaults; command output now identifies the actual connection and table being maintained.
- Strengthened fail-closed retention cleanup by validating raw `retention_months` and `automatic_cleanup_enabled` values before any preview or deletion. Unknown values now stop cleanup instead of falling back to defaults.
- Added PHPUnit and Playwright regressions for persisted badge updates, custom cache storage and malformed retention settings. No database migration, Composer dependency or npm dependency was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.28 package supports direct updates from 0.42.4 through 0.44.27.

## 0.44.27 - 2026-07-30

- Updated bundled `support-tickets` to 1.5.0 and added a red administration-menu badge that counts New and In-progress tickets requiring a staff response, hides at zero, displays `99+` above 99 and refreshes immediately after ticket actions.
- Made retention cleanup fail closed: scheduled and manual destructive cleanup now stop with an explicit error when module settings cannot be read; a new immutable migration seeds the default settings row without overwriting owner changes.
- Re-applied active-account and configured email-verification checks to subsequent player Livewire requests, added close throttling, restricted retention protection to Owner/Administrator and made repeated assign/close/protection actions idempotent.
- Decoupled ticket-history reading from the current write limit, added stable list ordering and preserved the bounded 2,000-message safety ceiling.
- Replaced unsafe byte truncation in the shared HTML sanitizer with a validation error, and added the matching UTF-8 byte-size check to the Tiptap source and compiled bundle.
- Added indexed scheduled cleanup for expired database cache/rate-limiter rows and daily orphan-media cleanup for news and pages.
- Added focused regressions for UTF-8 boundaries, cache maintenance, fail-closed retention, Livewire account-state revocation, historical messages, idempotent transitions and the support attention badge.
- The cumulative update line remains based on `0.42.4`; the 0.44.27 package supports direct updates from 0.42.4 through 0.44.26.

## 0.44.26 - 2026-07-30

- Added the core `ModuleAdminComponent` base class for administrative Livewire modules. It inherits `Livewire\Component` and uses `AuthorizesModuleAdminAccess` inside the PHPStan analysed `app` tree.
- Updated bundled `support-tickets` to 1.4.2 so `AdminTicketConversation` inherits the shared component without changing its authorization behaviour.
- Added release and module contract tests for the inheritance relationship. PHPStan rules, baseline and `treatPhpDocTypesAsCertain` remain unchanged.
- No database migrations, Composer dependencies, npm dependencies or public UI changes were introduced.
- The cumulative update line remains based on `0.42.4`; the 0.44.26 package supports direct updates from 0.42.4 through 0.44.25.

## 0.44.25 - 2026-07-30

- Updated bundled `support-tickets` to 1.4.1: the back action is anchored on the left, the ticket status occupies the centered toolbar column and staff actions remain aligned on the right with responsive stacking.
- Rounded the player reply, staff reply, message edit and internal-note text areas consistently with the rest of the account and administration controls.
- Expanded the strict Tiptap schema to 24 text colors and 12 highlights across the editor, server sanitizer and both public themes. Arbitrary `style` and `class` attributes remain forbidden.
- Fixed the Support Tickets module-image expectation and the Livewire authorization rendering assertion, and corrected the reported Pint formatting in the release builder and module access unit test.
- Added PHPUnit and Playwright contracts for the toolbar layout, rounded composers and expanded safe palette. No database migration was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.25 package supports direct updates from 0.42.4 through 0.44.24.

## 0.44.24 - 2026-07-30

- Replaced the legacy `document.execCommand` news/page editor with a locally bundled Tiptap 3.29.2 editor shared by both content types; no CDN or production Node.js runtime is required.
- Added undo/redo, headings, lists, quotes, inline and block code, safe text colors/highlights/sizes, four alignments, horizontal rules, tables, full-screen mode and character counting.
- Added accessible link and image dialogs, safe URL normalization, existing protected upload routes, alt text, captions, image alignment and four controlled image sizes.
- Extended `SafeHtmlSanitizer` only with table elements and bounded `data-*` tokens; arbitrary styles, classes, event handlers, external images and unsafe URL schemes remain forbidden.
- Updated both bundled public themes for identical `.news-prose` and `.cms-page-prose` rendering of the new tokens, responsive figures, tables and code blocks.
- Added the readable editor source, pinned npm dependencies, compiled bundle license notices and PHPUnit/Playwright contracts. Fixed required validation for image-only, divider-only and table-only documents and avoided invalid nested forms in editor dialogs.
- The cumulative update line remains based on `0.42.4`; the 0.44.24 package supports direct updates from 0.42.4 through 0.44.23.

## 0.44.23 - 2026-07-30

- Updated bundled `support-tickets` to 1.4.0 and moved manual database cleanup into a dedicated settings tab using the shared administration tab component. The save action now remains below the complete general-settings form.
- Reworked the Support Tickets administration detail page into a two-column layout with the conversation on the left and ticket metadata in a compact right-side panel, matching the established Users detail pattern.
- Replaced the previous Support Tickets filter card with the shared one-line administration filter bar and conditional reset action used by the Users catalogue.
- Replaced bundled module artwork for Daily Rewards, Promo Codes and Support Tickets with the supplied designs, normalized to the required validated 512×512 WebP format. Daily Rewards and Promo Codes are now 1.3.1.
- Added PHPUnit, Playwright, release-manifest and artwork-dimension regressions for the new tabs, layouts, filters and catalogue images. No database migration or dependency update was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.23 package supports direct updates from 0.42.4 through 0.44.22.

## 0.44.22 - 2026-07-30

- Integrated the externally verified module-access and browser-cache fixes into the current source without regressing the newer Windows SQLite cleanup and explicit browser login diagnostics.
- Normalized module role lists before constructing access rules, preserving runtime validation through `array<array-key, mixed> -> list<AdminRole>` while remaining compatible with strict PHPStan analysis.
- Added `RuntimeCacheConfigurationTest.php` to `repair_files` alongside the module access registry and browser runner so the direct patch force-replaces all three verified files on installations affected by incomplete earlier updates.
- Kept production `CACHE_LIMITER=database`; Playwright continues to use isolated array stores and a testing-only login limit.
- The cumulative update line remains based on `0.42.4`; the 0.44.22 package supports direct updates from 0.42.4 through 0.44.21.

## 0.44.21 - 2026-07-30

- Added a runtime role normalizer for module administration rules, preserving defensive validation while satisfying strict PHPStan contracts without weakening analysis.
- Added a test-only browser login limit override and environment-backed public authentication limits; Playwright now keeps production throttling unchanged while avoiding the shared 127.0.0.1 login cascade.
- Added `repair_files` support to the reproducible release builder and marked the module access registry and browser runner for forced replacement, repairing installations where an earlier patch was only partially applied.
- Added regressions for invalid module role values, browser login-limit isolation and repair-file release metadata.
- The cumulative update line remains based on `0.42.4`; the 0.44.21 package supports direct updates from 0.42.4 through 0.44.20.

## 0.44.20 - 2026-07-30

- Updated bundled `support-tickets` to 1.3.1 and closed the retention-cleanup race: eligible tickets are selected with `lockForUpdate()` and rechecked with the closed, unprotected and cutoff predicates immediately before deletion.
- Cleanup reports now count only tickets, messages and revisions that were actually deleted; tickets reopened, protected or given a newer close date during cleanup are preserved.
- Added a shared `ModuleAdminAuthorizer` and `AuthorizesModuleAdminAccess` trait so normal administration routes and module Livewire actions use the same fail-closed access rules.
- Converted the Support Tickets administration Livewire component from duplicated role checks to registered module route permissions and added Owner, Administrator, Editor, Auditor, player and guest regressions.
- Added explicit Playwright login diagnostics for HTTP 429 and server errors across administrator, player and support-ticket browser flows; production rate limits remain unchanged.
- Added browser-helper unit tests and module-development documentation explaining that route registration alone does not protect `/livewire/update`.
- The cumulative update line remains based on `0.42.4`; the 0.44.20 package supports direct updates from 0.42.4 through 0.44.19.

## 0.44.19 - 2026-07-30

- Removed redundant `AdminRole` runtime filtering and list reindexing from the typed module administration access registry, resolving the three reported PHPStan findings without weakening analysis.
- Isolated Playwright rate-limit counters with `CACHE_LIMITER=array`, so browser authentication no longer depends on the production database-cache store while the application default remains `database`.
- Restored the standard `cache_locks` table default for the database cache store.
- Made browser test shutdown wait for the PHP process tree and retry locked SQLite cleanup on Windows; a cleanup failure no longer hides the original Playwright failure.
- Added regressions for browser limiter isolation, the database lock-table default and locked-file cleanup behavior.
- The cumulative update line remains based on `0.42.4`; the 0.44.19 package supports direct updates from 0.42.4 through 0.44.18.

## 0.44.18 - 2026-07-30

- Added a separate `cache.limiter` store with `CACHE_LIMITER=database` by default, keeping the general application cache file-based while moving login and other rate-limit counters to the existing CMS `cache` table.
- Added `kaevcms:runtime-directories --probe`, which creates the required Laravel runtime tree and verifies nested directory and file creation without leaving probe files behind.
- Added runtime-directory verification before and after `optimize:clear` in Windows setup/update, the Web Installer, Web/CLI update installation and interrupted-update recovery.
- Strengthened Web Installer write checks to verify recursive directory creation rather than only writing one file into an existing parent.
- Added PHPUnit, Web Installer, Windows workflow and Ubuntu/VDS documentation regressions for the limiter store, runtime repair order and nested write probes.
- Documented safe `storage` and `bootstrap/cache` ownership repair without `chmod 777` and added a PHP-FPM-user diagnostic command.
- The cumulative update line remains based on `0.42.4`; the 0.44.18 package supports direct updates from 0.42.4 through 0.44.17.

## 0.44.17 - 2026-07-29

- Updated bundled `support-tickets` to 1.3.0, restored ticket pages after the Livewire conversion and corrected the remaining Pint PHPDoc alignment finding.
- Passed the authenticated player explicitly to account-theme layouts and resolved the administration path inside the staff Livewire component instead of accepting a nullable route parameter.
- Moved ticket, message, revision, open-ticket and character limits into owner-only module settings with bounded validation and unchanged safe defaults.
- Added a module migration and regressions for configurable limits while preserving existing tickets, messages and cleanup settings.
- The cumulative update line remains based on `0.42.4`; the 0.44.17 package supports direct updates from 0.42.4 through 0.44.16.

## 0.44.16 - 2026-07-29

- Updated bundled `support-tickets` to 1.2.0 and corrected the sorted release-file contract, Pint formatting and strict Playwright locators reported by the 0.44.15 quality gates.
- Changed the player landing page to show **My tickets** first and open the new-ticket form only from a compact action button; removed the redundant limits notice while preserving field counters and server-side validation.
- Converted ticket creation, player replies, staff replies, status actions, assignment, message editing and internal notes to Livewire interactions without full-page reloads.
- Added dedicated scrollable conversation panels that initially show the latest 50 messages and retain the existing **Show previous messages** control.
- Changed the staff-facing `in_progress` label to **Awaiting your reply** while preserving the player-facing **In progress** label.
- Reworked the internal-note composer into a compact expandable control and aligned module settings with the standard administration toggle layout.
- Added Livewire feature regressions, stable browser selectors, no-reload checks, mobile overflow coverage and release-manifest coverage for the new module files.
- The cumulative update line remains based on `0.42.4`; the 0.44.16 package supports direct updates from 0.42.4 through 0.44.15.

## 0.44.15 - 2026-07-29

- Updated bundled `support-tickets` to 1.1.0 and fixed stale PHPUnit, Playwright and Pint contracts from 0.44.14.
- Collapsed the internal-note composer by default while preserving staff-only notes and immutable edit history.
- Split Editor access into independent view, reply/status and internal-note settings; Owner settings remain private and Auditor remains read-only.
- Added limits of 10 tickets per player per day, 100 player messages per day, 300 total messages per ticket and 20 stored revisions per staff message.
- Added cursor pagination for the latest 50 conversation messages and database indexes for player, staff assignment and retention queries.
- Added configurable 6/12/24/36-month or indefinite retention, per-ticket cleanup protection, dry-run/manual cleanup, daily scheduled batch cleanup and an optional manual SQLite `VACUUM` command.
- Expanded RU/EN module documentation and regressions for limits, pagination, permissions, indexes and cleanup safety.
- The cumulative update line remains based on `0.42.4`; the 0.44.15 package supports direct updates from 0.42.4 through 0.44.14.

## 0.44.14 - 2026-07-29

- Added bundled `support-tickets` module 1.0.0 with private player tickets, approved categories and player-facing statuses.
- Owners and administrators can process tickets; editor access is optional and module-scoped; auditors remain read-only.
- Added assignment, internal notes, close/reopen workflow, character limits, duplicate/flood protection and immutable staff-message revision history.
- Added a reusable module administration access registry so future modules can define scoped role access without receiving global `modules.manage`.
- Added RU/EN module documentation, feature/unit regressions and Playwright player/staff/mobile coverage.
- Module artwork is intentionally left for the owner at `modules/support-tickets/assets/module.webp` (512×512 WebP).
- The cumulative update line remains based on `0.42.4`; the 0.44.14 package supports direct updates from 0.42.4 through 0.44.13.

## 0.44.13 - 2026-07-29

- Replaced the obsolete Auditor Promo Codes journal label selector with a route-based locator scoped to the main content area.
- The browser scenario now opens the activation journal, verifies its URL, and confirms that Auditor read-only mode remains active.
- Kept module translations, Auditor permissions, and production navigation unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.13 package supports direct updates from 0.42.4 through 0.44.12.

## 0.44.12 - 2026-07-29

- Removed the redundant `$featureAfter !== null` comparison in `GameServerManager`; PHPStan can infer the paired assignment once `$featureBefore` is non-null.
- Kept `treatPhpDocTypesAsCertain` enabled and did not weaken static analysis.
- Replaced Auditor system-tab browser selectors based on exact Russian labels with route-based scoped selectors for Security, System information, and System updates.
- Corrected the obsolete **Сведения о системе** expectation without changing translations, role permissions, Blade navigation, or production behavior.
- The cumulative update line remains based on `0.42.4`; the 0.44.12 package supports direct updates from 0.42.4 through 0.44.11.

## 0.44.11 - 2026-07-29

- Fixed the remaining Laravel Pint `class_attributes_separation` findings in `Admin` and `AdminFactory` by removing duplicate blank lines between methods.
- Stabilized the Auditor Playwright navigation test: collapsible sidebar groups are now opened explicitly and verified through their `open` state before nested links are used.
- Scoped duplicate navigation labels to the administrator sidebar and replaced the ambiguous global **Settings** locator with the existing `data-admin-settings-link` selector.
- Kept production navigation behavior unchanged. Tests no longer depend on cookies, persisted Livewire sidebar state, or `localStorage` leaving a menu group open.
- Access permissions, Auditor visibility, and server-side read-only enforcement are unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.11 package supports direct updates from 0.42.4 through 0.44.10.

## 0.44.10 - 2026-07-29

- Removed the redundant **Demo viewer** role and retained one trusted global read-only role: **Auditor**.
- Migrated legacy `read_only` and `demo_viewer` administrator accounts to Auditor and incremented their session version so existing sessions are revoked.
- Preserved the owner-only Auditor assignment rule and the permission-subset guard that prevents administrators from creating accounts with broader access than their own.
- Updated browser-test credentials and coverage to use Auditor. Auditor can inspect every administration section and sensitive journals, while every HTTP and Livewire mutation remains blocked server-side.
- Documented that published Auditor credentials are acceptable only on a separate isolated demonstration stand containing artificial users, test databases, non-secret connection values, and no production data.
- Fixed the remaining Laravel Pint `single_line_empty_body` finding in `AccountCharacterDirectory`.
- Removed the obsolete demo-only redaction, navigation, role, factory, seeder, translation, CSS, and regression branches. Existing security coverage was converted to Auditor coverage rather than weakened.
- Fixed the role-access regression that could reach the two-factor reset throttle while testing two protected accounts; only the remaining protected Auditor account is now exercised.
- The cumulative update line remains based on `0.42.4`; the 0.44.10 package supports direct updates from 0.42.4 through 0.44.9.

## 0.44.9 - 2026-07-29

- Replaced the unsafe global `read_only` role with two explicit system roles: trusted **Auditor** and public **Demo viewer**.
- Added separate view permissions for content, users, administrators, rewards, security, administrator-panel settings, system updates, and queue operations. Auditor and Demo viewer permissions are explicitly enumerated and never inherit future permissions automatically.
- Restricted Auditor and Demo viewer assignment to the owner. Administrators can assign only Administrator or Editor and cannot edit, disable, reset passwords, or reset two-factor authentication for owner-managed auditor/demo accounts.
- Added a general permission-subset rule so a non-owner cannot assign a role containing permissions that the actor does not have.
- Limited Demo viewer to dashboard, content, themes, modules, and safe site/registration/game-account/language settings. Users, administrators, journals, servers, mail, security, administrator-panel settings, system diagnostics, queues, and updates are denied server-side.
- Classified bundled promo-code activation and daily-reward claim journals as sensitive reward history: Demo viewer cannot see their links or open the routes, while Auditor retains read-only access.
- Redacted the public administrator contact address in Demo viewer mode and preserved session-only language switching for shared read-only credentials.
- Migrated legacy `read_only` accounts to the safer `demo_viewer` role and incremented their session version so previously issued sessions are revoked.
- Replaced role-specific system-update checks with explicit `system_updates.view` and `system_updates.manage` permissions.
- Fixed the two PHPStan findings in GameServer feature persistence and feature settings, and restored the expected Pint shape for `AccountCharacterDirectory`.
- Added role-escalation, protected-account takeover, trusted-auditor, safe-demo, PII/infrastructure denial, migration, Livewire mutation, updater, and browser regressions without weakening existing tests.
- The cumulative update line remains based on `0.42.4`; the 0.44.9 package supports direct updates from 0.42.4 through 0.44.8.

## 0.44.8 - 2026-07-29

- Updated the bundled account-theme regressions to the shipped `1.6.2` manifests instead of the obsolete `1.6.0` expectation.
- Removed the unused lower-case `at least one digit.` translation key, preserving the canonical `At least one digit.` key and restoring case-insensitive RU/EN catalog uniqueness.
- Restored Laravel Pint formatting in `AccountCharacterDirectory` and the registration-policy feature tests without weakening or removing assertions.
- Registration policy behavior, account themes, database schema, modules, and user-owned runtime data are unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.8 package supports direct updates from 0.42.4 through 0.44.7.

## 0.44.7 - 2026-07-29

- Unified `settings-field` inputs, selects, and textareas with the same explicit one-pixel control border used by standard `form-group` fields, removing the thicker browser-default border visible in Game account settings and other compact forms.
- Added a configurable website username policy: minimum and maximum length plus independent hyphen and underscore allowances. Latin letters and digits remain the safe base character set.
- Added a configurable website password policy: minimum length, letter requirement, mixed-case requirement, digit requirement, and symbol requirement. Safe ranges are validated in the administration panel.
- Applied the saved password policy consistently to new registration, password reset, and player-account password changes. Public forms render matching HTML constraints and localized requirement summaries.
- Added administration, registration, reset, account-password, translation, style, validation, persistence, and audit regressions without weakening existing coverage.
- Updated both bundled account themes to `1.6.2`. The cumulative update line remains based on `0.42.4`; the 0.44.7 package supports direct updates from 0.42.4 through 0.44.6.

## 0.44.6 - 2026-07-29

- Kept the player-facing `Return to city` action visible whenever character rescue is enabled and supported, including while the character is online. Online characters now open an informational modal instructing the player to log out; the submit action is hidden and guarded from submission.
- Preserved the mandatory server-side online-state check and the existing atomic database write, so the UI change does not weaken rescue safety.
- Added feature and shared-runtime regressions for online/offline button state, the online informational modal, and the non-submittable online form path.
- Corrected the read-only role navigation assertion to use the actual Russian label `Журнал действий` without weakening section-access coverage.
- Restored Laravel Pint formatting in `AccountCharacterDirectory`, including the empty constructor body and PHPDoc alignment.
- Updated both bundled account themes to `1.6.1`. The cumulative update line remains based on `0.42.4`; the 0.44.6 package supports direct updates from 0.42.4 through 0.44.5.

## 0.44.5 - 2026-07-29

- Added the system `read_only` administrator role for public demo credentials, audits, and support reviews. The role is selectable in administrator management without a database migration because the existing role column is string-based.
- Read-only administrators can open every registered administration section, including users, other administrator profiles, server settings, modules, audit logs, queue information, and system-update history. Pages render with the existing read-only notice and disabled mutation controls.
- Added a strict server-side mutation guard: non-safe administration requests are rejected before controllers run, own-profile changes are blocked, Livewire GameServer/LoginServer actions explicitly deny read-only users, automatic server-status refresh is disabled, and language switching remains session-only without changing the administrator row.
- Read-only users may inspect owner-only system-update pages, but cannot upload, apply, recover, or discard packages. Viewing a staged update does not create a maintenance recovery secret for this role.
- Added role, permissions, administrator-assignment, all-section visibility, HTTP mutation, Livewire mutation, system-update visibility, language persistence, and dashboard auto-refresh regressions. Existing permission tests were extended rather than weakened.
- The cumulative update line remains based on `0.42.4`; the 0.44.5 package supports direct updates from 0.42.4 through 0.44.4.

## 0.44.4 - 2026-07-29

- Moved character rescue configuration from the separate Additional administration page into the existing GameServer drawer as a compact **Features** tab. The fields now reuse the same `server-form-grid` and `form-group` controls as the General and Miscellaneous tabs.
- The rescue switch follows the maintenance-mode interaction: parameters remain hidden until the feature is enabled. Existing per-server settings, capability checks, validation, cooldowns, audit events, and player behavior are preserved.
- Removed the obsolete Additional sidebar item, controller, request, routes, and views. Versioned deletions remove those files during patch and cumulative updates.
- Fixed sidebar interaction priority so an active or `data-current` item remains blue while hovered instead of falling back to the ordinary dark hover state.
- Replaced the separate-page regressions with Livewire and Playwright coverage for the GameServer Features tab, compact conditional fields, validation tab activation, capability blocking, sidebar item count, and active-hover color persistence. Tests were not weakened.
- Normalized the English `Login servers` label so the existing sidebar-order regression matches the rendered interface.
- The cumulative update line remains based on `0.42.4`; the 0.44.4 package supports direct updates from 0.42.4 through 0.44.3.

## 0.44.3 - 2026-07-29

- Restored `deployment/release-files.json` as the required schema-1 object with a sorted `required_files` list; the 0.44.2 archive accidentally contained only the literal strings `schema` and `required_files`, causing `apply-0.44.2.ps1` to stop before update execution.
- Synchronized the required current apply-script path with `deployment/windows/apply-0.44.3.ps1` and retained the existing PowerShell and PHPUnit release-metadata checks that reject malformed manifests.
- Character rescue runtime logic, database migrations, GameServer capabilities, account themes, and the Additional administration interface are unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.3 package supports direct updates from 0.42.4 through 0.44.2.

## 0.44.2 - 2026-07-29

- Reworked the separate GameServer Additional administration page to follow the shared admin design system: the index now reuses server-style summary cards instead of a custom cramped row layout, while the per-server editor stays in the standard settings-form pattern.
- Moved the sidebar entry below Login servers, renamed it to `Additional`, and excluded `/admin/settings/game-server-features*` from the Settings sidebar highlighter so the separate page no longer marks Settings as current.
- Added regression coverage for the new sidebar order, the Additional label, the separate-page heading, the server-card layout, and the client-side rule that keeps Settings inactive on the Additional page.
- Restored Laravel Pint compliance for `AccountCharacterDirectory` after the rescue-related follow-up changes. Runtime rescue logic, migrations, capabilities, and user data remain unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.2 package supports direct updates from 0.42.4 through 0.44.1.

## 0.44.1 - 2026-07-28

- Updated `ServerDriverRegistryTest` to include the optional Mobius `characters.x`, `characters.y`, and `characters.z` schema columns introduced for character rescue.
- Reused the existing literal translation key for the unavailable character-rescue state, restoring complete RU/EN translation coverage.
- Fixed Laravel Pint violations in the character directory, rescue service, and feature settings, including removal of an unused import.
- Character rescue runtime behavior, database migrations, driver capabilities, themes, modules, and user-owned runtime data are unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.1 package supports direct updates from 0.42.4 through 0.44.0.

## 0.44.0 - 2026-07-28

- Added a separate **Game servers → Features** administration section so optional player services can be configured per GameServer without overloading connection cards.
- Added safe direct offline character rescue with owner-defined destination coordinates, minimum offline delay, per-character cooldown, confirmation modal, atomic ownership/state checks, cache invalidation, UUID operation journal, and redacted audit events.
- Added the optional Mobius `character_rescue` capability. Support is detected from the existing unified schema-profile diagnostics and requires the `characters` coordinate, ownership, offline-state, deletion, and access-level columns.
- Added reusable `game_server_features` storage and a dedicated `CharacterRescueGateway` contract so future character services and non-Mobius schemas can be added without changing the player interface.
- Updated both bundled account themes to 1.6.0 with responsive character rescue actions and the shared account navigation runtime.
- Added Russian/English documentation plus feature, authorization, validation, cooldown, ownership, online-state, idempotency, schema-diagnostics, and direct database-write regressions.
- The cumulative update line remains based on `0.42.4`; the 0.44.0 package supports direct updates from 0.42.4 through 0.43.0.

## 0.43.0 - 2026-07-28

- Prepared the first public release line and reset both the cumulative update baseline and recovery floor to `0.42.4`; the first cumulative package is `KaevCMS-cumulative-update-0.42.4-to-0.43.0.zip`.
- Removed the frozen `docs/history/0.32.10-reference` tree and obsolete `AUDIT-0.30.0.md` / `AUDIT_0.29.0.md` compatibility stubs from release payloads.
- Added versioned deletion coverage and regressions proving the obsolete documentation is removed by patch and cumulative updates without touching `.env`, storage, databases, uploads, modules, themes, or game-server integrations.
- Fixed the Windows update-workflow regression for a freshly reset cumulative baseline: when no superseded pending targets exist, the test now creates a normal current pending marker instead of passing an empty `ToVersion`.
- Database schemas, Composer/npm dependencies, bundled module/theme versions, runtime behavior, and user-owned files are unchanged.

## 0.42.4 - 2026-07-28

- Completed the Laravel Pint formatting of `deployment/release/build-release.php` by adding the required blank line before the non-initial `continue` statement in the ZIP extraction loop.
- Added a mandatory `composer lint` preflight to the official Windows release command so release archives are not created from a Pint-invalid source tree.
- Runtime CMS behavior, database schema, modules, themes, game drivers, updater contracts, and archive format are unchanged.

---

Older entries (before 0.42.4) are in [docs/CHANGELOG-ARCHIVE.md](docs/CHANGELOG-ARCHIVE.md).
