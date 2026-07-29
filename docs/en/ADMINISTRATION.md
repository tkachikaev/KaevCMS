# Administration

The owner has full access, administrators manage operational settings without owner-only security actions, editors work with permitted content areas, and read-only administrators can inspect every section without changing CMS or game-server data.

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

## Read-only role

Use the Read-only role for public demonstrations, audits, and support reviews. It can open every administration section and view system-update history, but all create, update, delete, maintenance, queue, module, mail, server, and Livewire actions are rejected server-side. Language switching affects only the current session and does not update the shared demo account.
