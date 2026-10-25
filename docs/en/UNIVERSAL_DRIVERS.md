# Universal drivers — iterations 1–2 (JSON + read-only LoginServer)

**Status: experimental and NOT enabled in the CMS.** The existing production PHP LoginServer/GameServer drivers, registration, password updates, character queries, castles and reward delivery are unchanged. There is no admin editor or import action yet. A read-only LoginServer query adapter exists for isolated validation only; it is not wired into production resolvers. A valid profile file is not proof of compatibility with a game build.

## Bundled references

- `driver-profiles/bundled/login-l2j-mobius.json` — Mobius LoginServer;
- `driver-profiles/bundled/login-l2j-mobius-legacy.json` — Mobius C1/C4 LoginServer;
- `driver-profiles/bundled/login-rusacis.json` — RUSaCis LoginServer;
- `driver-profiles/bundled/game-l2j-mobius.json` — Mobius GameServer (legacy/modern schema choice);
- `driver-profiles/bundled/game-rusacis.json` — RUSaCis GameServer.

There is only one existing Mobius GameServer class with legacy/modern schema detection, not a separate legacy game driver.

## Format v1

Each profile has `id` (stable ID), `name` (display name), `type` (`login` or `game`), `format_version: 1`, and `config`. Identity is `type:id`, not the display name. Configuration includes `database` (`dialect`, charset, collation), `requirements`, `capabilities`, and the field mappings. Hostnames, ports, credentials, arbitrary SQL/PHP or paths must not be embedded.

Login profiles describe `accounts.fields`, `accounts.ownership`, insertion sources under `accounts.create.columns`, and a trusted password scheme (`sha1_base64`, or `bcrypt_2a` with cost 4–16). Read-only account profiles may omit the password mapping. LoginServer email is independent of the CMS user email.

Game profiles use `characters.owner`, `characters.fields` sources (`column`, `related`, `choice`, `eav`, `operator`), limited `relations`, `filters`, `rankings`, and `features`. Column/table values are validated as identifiers, never SQL expressions; future query adapters must bind data values. The optional `heroes` and `castle` schemas must not break the basic character list.

**Legacy behavior mapped but not yet implemented by the new engine:** RUSaCis `herountil` OR active `heroes`, nullable or zero `deletetime`; Mobius `karma` versus `reputation` by chronicle/schema; reusable Castle ID → localized name catalog; server-account ownership verification. These are verified during read-only parity iterations, not inferred from a valid JSON alone.

`format_version` is the structure version of JSON, not the CMS release or game version. The initial migrator explicitly accepts only version 1; unknown versions fail closed. Future format versions require explicit migration logic.

## Tests

Dependency-free offline contract smoke:

```bash
php tests/Integration/UniversalDrivers/validate-profiles.php
```

Unit tests (when PHPUnit is installed) also compare bundled table requirements against the current `ServerDriverRegistry` and schema inspectors and check hostile JSON. The MySQL 8.4 CI job installs the locked Composer dependencies, creates randomly named temporary databases, validates the character fixtures and compares LoginServer read-only results against the legacy drivers. Run this only against a disposable local MySQL test instance:

```bash
KAEV_UNIVERSAL_MYSQL_TEST=1 KAEV_MYSQL_TEST_HOST=127.0.0.1 \
KAEV_MYSQL_TEST_USER=root KAEV_MYSQL_TEST_PASSWORD='TEST_ONLY' \
php tests/Integration/UniversalDrivers/mysql-fixtures.php
```

Read-only **LoginServer** parity is checked by this job, but it has not been executed locally unless a MySQL test environment is available. GameServer parity belongs to iterations 3–4. Valid JSON is a structural contract, not proof of a working game login.

## Next steps

Iteration 2 adds read-only LoginServer queries on MySQL, still alongside the PHP drivers. Admin UI, built-in overrides/tombstones, imported custom profiles, and per-connection validated snapshots come later. `database.dialect` keeps the format structurally extensible for a future SQL Server/PTS adapter, but `sqlserver` is deliberately rejected in v1 and no SQL Server dependency is installed. No database migrations are required for this iteration.

## Iteration 2 — LoginServer read-only path

`app/Services/GameAccounts/UniversalLoginAccountReader.php` supplies `summary`, `inspect` and `diagnose` using validated LoginServer JSON profiles and an existing Laravel MySQL connection. All login values are query-bound. Optional account metadata can be absent; a missing access column yields `unknown`, not an invented `active` state. Mobius inspection fails closed if the configured email column is absent. No JSON-controlled SQL expressions, registration, password changes or production switching are introduced.

The disposable MySQL CI fixture job now installs locked dependencies and directly compares legacy and JSON-powered read-only results for RUSaCis, Mobius and Mobius Legacy, including missing accounts, wrong credentials, SQL injection as a login value, and missing optional fields. Only unique, test-owned databases are dropped on completion. No production server connection is needed or permitted.
