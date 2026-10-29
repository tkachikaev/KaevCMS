# Administration

The owner has full access, administrators manage operational settings without owner-only security actions, editors work with permitted content areas, and auditors inspect trusted administration data without changing it.

Main areas:

- Dashboard with server availability, mail/runtime diagnostics, a server-disk usage bar, safe KaevCMS database size statistics, and the external-database health snapshot.
- Administrators, roles, two-factor authentication, and sign-in history.
- CMS users: search, details, disabling and re-enabling accounts (users are not deleted), verification, and password-reset mail.
- General settings, languages, themes, registration, and security.
- LoginServer and GameServer connections, active schema profiles, driver capabilities, and table state.
- News, pages, navigation, and media.
- Mail connection, delivery mode, templates, and custom messages.
- Modules, reward journal, failed jobs, audit log, and manual updater.

The administrator path can be changed by the owner. Never expose database credentials in screenshots, support messages, or audit notes.

Administrator sign-in is rate-limited and supports two-factor authentication with recovery codes. Passwords are hashed with Argon2id (bcrypt when Argon2id is unavailable), and older hashes are upgraded at the next sign-in. Changing an administrator's role or access ends that administrator's old sessions. Sign-ins and security changes are recorded in the audit log; sensitive values are redacted from audit records.

**Settings → System information** shows the version, scheduler, queues, encryption health, database safety, proxy configuration, and redacted LoginServer/GameServer diagnostics: availability, last successful connection, a safe error class, `SELECT 1` latency, schema profile, driver capabilities, and the state of required and optional tables.

## Auditor role

The Auditor role is intended for trusted internal reviews, support diagnostics, security audits, and read-only demonstrations on a separate isolated stand. It can inspect users, administrators, servers, mail, security, journals, queues, diagnostics, and system-update history. All create, update, delete, maintenance, queue, module, mail, server, and Livewire mutations are rejected server-side.

Only the owner can create an auditor or assign this role. Administrators cannot reset an auditor password, disable the account, reset its two-factor authentication, or convert another account into an auditor.

An auditor sees personal and infrastructure data. Never publish auditor credentials for a production installation or a copy of production data. A public demonstration stand must use artificial users, test databases, non-secret connection values, and isolated infrastructure.

Legacy `read_only` and `demo_viewer` accounts are migrated to Auditor and their existing sessions are revoked.

## Diagnostic package

Open **Settings → System information** and select **Download diagnostic package**. The generated ZIP contains a readable report plus separate JSON files for versions, environment, write access, CMS and external database state, scheduler, queue, disk space, modules, module migrations, core migrations, recent update state, and recent error signatures.

The package never copies `.env`, `APP_KEY`, passwords, tokens, cookies, database credentials, user records, raw databases, or complete Laravel logs. Recent log entries are reduced to timestamp, severity, exception class, and a stable fingerprint. Email addresses, IP addresses, credentials, and absolute filesystem paths are redacted before anything is written to the ZIP.

The archive is generated in a temporary directory under `storage/app/kaevcms/diagnostics`, downloaded immediately, and deleted after the response. Abandoned packages older than 24 hours are removed automatically the next time a package is created. The PHP `zip` extension and write access to `storage` are required. Identical recent warning/error signatures are grouped with an occurrence count and first/last timestamps so repeated application errors do not flood the archive.

An administrator can download up to three packages per minute. Further clicks return to System information and show a small inline wait message instead of an HTTP 429 error page.

An owner, administrator, or auditor with system-information access can download the package. Creating the archive is recorded in the audit log. Inspect the files before sending them to support.

## Notification center

The bell in the top administration panel is personal to the signed-in administrator. The red badge is hidden at zero and is capped at `99+`. Open the compact panel to switch between **All** and **Unread**, follow an event to its related ticket, module, server, update or system page, mark everything as read, clear read entries, or clear the whole list after confirmation.

KaevCMS sends only actionable events: new player tickets and replies, module updates or migrations, pending CMS migrations, failed updates, queue/Scheduler failures, configured LoginServer/GameServer unavailability, low disk space, a leftover public installer, and critical diagnostics. Routine successful sign-ins and technical actions remain in audit logs instead.

Read does not mean resolved. Reading or clearing a server failure removes its unread/list state only for the current administrator; server diagnostics continue to show the real condition. Repeated unresolved failures update one internal event and do not immediately return after dismissal. When the problem is resolved and later happens again, a new notification is created.

Open **Settings → Notifications** or use the gear to the right of **All / Unread** in the bell panel. The page uses the standard administration switches and lets an owner or administrator enable or disable grouped sources: Technical Support, module updates, KaevCMS updates, background tasks, LoginServer availability, GameServer availability, low disk space, a leftover installer, and critical system diagnostics. The `?` control beside every source explains exactly what it covers. An auditor can inspect the page in read-only mode.

Disabling a source stops new events without deleting entries that are already in an administrator's list. Disabling an active recurring problem resolves its notification lifecycle; if the source is enabled again while the problem still exists, the next scan creates a new event. The underlying diagnostics and current state are never disabled.

The scheduler runs `kaevcms:notifications-scan` every minute and `kaevcms:notifications-clean` daily. Settings can keep records for 30, 60, 90 or 180 days and can disable automatic physical cleanup. A manual `kaevcms:notifications-clean --days=<number>` run remains available. Notification delivery is fail-safe and never blocks ticket creation, update rollback or monitoring.

## Google sign-in (social-auth rollout)

On **Admin → Settings → Registration**, find the Social authorization section and expand Google. Create a *Web application* in [Google Cloud Console](https://console.cloud.google.com/apis/credentials), register the exact callback URL shown by KaevCMS, save **Client ID** and **Client Secret**, and explicitly enable Google. Production `APP_URL` must be HTTPS; localhost is allowed for development. Saving credentials alone does not enable sign-in.

Players can log in with Google and create CMS accounts. Email matching never links existing CMS accounts automatically: players sign in normally and connect Google in Account → Security after confirming their identity. External addresses (outside Gmail/Google Workspace) require an additional verification email from KaevCMS; configure mail delivery first. Disabling the provider preserves existing links but prevents further Google logins.

Google, Yandex ID and VK ID are implemented in this rollout. Full QA and a test login against an isolated HTTPS environment are required before production enablement.


## Yandex ID sign-in (social authentication stage)

Open **Admin → Settings → Registration → Social authentication**, then Yandex ID. Register a **user sign-in application** at [Yandex OAuth](https://oauth.yandex.ru/), add the exact callback URL shown in the admin panel, and request `login:email` and `login:info` permissions. Save Client ID and Client Secret; enable the provider separately. HTTPS `APP_URL` is required. Password and Google sign-in remain independent.

On first sign-in, players verify their email through a CMS message and choose a username. Matching an existing email **never links accounts automatically**; sign in normally and connect Yandex under **Account → Security**. Existing links are found using Yandex's numeric `id`, not email or `psuid`. OAuth does not grant access to game accounts or payments.


## VK ID sign-in (social authentication stage)

Under **Admin → Settings → Registration → Social authentication**, open **VK ID**. Register a web application in [VK ID](https://id.vk.ru/), add the exact callback URL shown in KaevCMS, enter its **Client ID**, and explicitly enable it. Client Secret is not required for the regular browser Authorization Code + PKCE flow. Production requires HTTPS `APP_URL`. Request `email` permission in VK ID.

KaevCMS uses the **new** VK ID (`id.vk.ru`), not legacy `oauth.vk.com`. The callback's `device_id` and PKCE S256 are required; no web SDK or CDN is needed. Email must **always be verified using a CMS message** before registering a new user. A matching email never automatically links accounts. Existing users connect VK ID through **Account → Security**, after step-up verification. Disabling VK ID leaves existing links intact.

**Note:** applications requiring `service_token` need a separately approved server configuration; this implementation supports the standard PKCE web flow without it. Verify a real callback and browser behavior against an isolated HTTPS site before enabling production sign-in.


## Telegram sign-in (iteration 6: integration staged, pending approved JWT library)

In **Admin → Settings → Registration → Social authentication**, open Telegram. Create a bot using [@BotFather](https://t.me/BotFather) and register both your origin and the exact CMS Callback URL under **Login Widget**. Enter **Client ID** and **Client Secret** in KaevCMS. Production requires an HTTPS `APP_URL`. The integration requests `openid profile` with Authorization Code + PKCE S256; it never requests message access or phone numbers and does not use the legacy iframe/HMAC widget.

Telegram OIDC does **not** provide an email address. First-time players enter an email and verify it through KaevCMS before choosing a username. Existing players can link Telegram under **Account → Security** after re-confirming their identity. Accounts are never automatically merged by email and only one Telegram identity can be linked per CMS user.

**Iteration 6 limitation:** the currently approved `composer.lock` has no JWT/JWKS verification library. The dependency guard prohibits installing one without owner approval. Telegram **cannot be enabled** until a vetted library is approved and installed (fail-closed). Code is prepared for maintained `firebase/php-jwt` v6 (`Firebase\JWT\JWT` + `JWK`), but no dependency was changed. Once approved, validate RS256 signatures using Telegram's fixed JWKS and claims `iss/aud/azp/exp/iat/nonce`, test rejection of other algorithms and perform a real HTTPS callback test. Configure **RS256** in BotFather.

Official documentation: https://core.telegram.org/bots/telegram-login
