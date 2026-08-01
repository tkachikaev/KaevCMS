# Administration

The owner has full access, administrators manage operational settings without owner-only security actions, editors work with permitted content areas, and auditors inspect trusted administration data without changing it.

Main areas:

- Dashboard, runtime diagnostics, and the safe external-database health snapshot.
- Administrators, roles, two-factor authentication, and sign-in history.
- CMS users, account status, verification, and password-reset mail.
- General settings, languages, themes, registration, and security.
- LoginServer and GameServer connections, active schema profiles, driver capabilities, and table state.
- News, pages, navigation, and media.
- Mail connection, delivery mode, templates, and custom messages.
- Modules, reward journal, failed jobs, audit log, and manual updater.

The administrator path can be changed by the owner. Never expose database credentials in screenshots, support messages, or audit notes.

## Auditor role

The Auditor role is intended for trusted internal reviews, support diagnostics, security audits, and read-only demonstrations on a separate isolated stand. It can inspect users, administrators, servers, mail, security, journals, queues, diagnostics, and system-update history. All create, update, delete, maintenance, queue, module, mail, server, and Livewire mutations are rejected server-side.

Only the owner can create an auditor or assign this role. Administrators cannot reset an auditor password, disable the account, reset its two-factor authentication, or convert another account into an auditor.

An auditor sees personal and infrastructure data. Never publish auditor credentials for a production installation or a copy of production data. A public demonstration stand must use artificial users, test databases, non-secret connection values, and isolated infrastructure.

Legacy `read_only` and `demo_viewer` accounts are migrated to Auditor and their existing sessions are revoked.

## Diagnostic package

Open **Settings → System information** and select **Download diagnostic package**. The generated ZIP contains a readable report plus separate JSON files for versions, environment, write access, CMS and external database state, scheduler, queue, disk space, modules, module migrations, core migrations, recent update state, and recent error signatures.

The package never copies `.env`, `APP_KEY`, passwords, tokens, cookies, database credentials, user records, raw databases, or complete Laravel logs. Recent log entries are reduced to timestamp, severity, exception class, and a stable fingerprint. Email addresses, IP addresses, credentials, and absolute filesystem paths are redacted before anything is written to the ZIP.

The archive is generated in a temporary directory under `storage/app/kaevcms/diagnostics`, downloaded immediately, and deleted after the response. Abandoned packages older than 24 hours are removed automatically the next time a package is created. The PHP `zip` extension and write access to `storage` are required.

An owner, administrator, or auditor with system-information access can download the package. Creating the archive is recorded in the audit log. Inspect the files before sending them to support.

## Notification center

The bell in the top administration panel is personal to the signed-in administrator. The red badge is hidden at zero and is capped at `99+`. Open the compact panel to switch between **All** and **Unread**, follow an event to its related ticket, module, server, update or system page, mark everything as read, clear read entries, or clear the whole list after confirmation.

KaevCMS sends only actionable events: new player tickets and replies, module updates or migrations, pending CMS migrations, failed updates, queue/Scheduler failures, configured LoginServer/GameServer unavailability, low disk space, a leftover public installer, and critical diagnostics. Routine successful sign-ins and technical actions remain in audit logs instead.

Read does not mean resolved. Reading or clearing a server failure removes its unread/list state only for the current administrator; server diagnostics continue to show the real condition. Repeated unresolved failures update one internal event and do not immediately return after dismissal. When the problem is resolved and later happens again, a new notification is created.

The scheduler runs `kaevcms:notifications-scan` every minute and `kaevcms:notifications-clean` daily. Old records are physically removed according to `cms.admin_notifications.retention_days` (90 days by default). Notification delivery is fail-safe and never blocks ticket creation, update rollback or monitoring.
