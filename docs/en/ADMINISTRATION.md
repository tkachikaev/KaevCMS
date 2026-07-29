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
