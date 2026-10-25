# Universal drivers — development history / current version 0.53.11

## Using the editor

“Login server” describes accounts and game passwords; “Game server” describes characters and statistics. “Edit” opens an existing profile. For a new driver, select a current configuration to use as the starting point of an unsaved draft.

The login editor has two steps. First enter a display name (any language), short file name (Latin characters, digits and separators), database character set, collation and game password format. A new short name may be blank to generate it from the display name; it is fixed after creation. Then map fields: **data needed by the website → game database table → column containing the data**. For example: “Account login → accounts → login”. Editing the table in any row updates all rows because account data comes from one table.

Login and password are required. Leave other fields blank if your server does not have them. Account lookup, creation, password changes and status reading are configured automatically, without feature switches. Saving generates primary table requirements from mapped fields and extra registration values. The old primary table name is replaced, while requirements for other tables in the source profile are preserved. All configured columns are required during verification; leave nonexistent optional fields unmapped. The ownership column follows the source profile’s login/ID identity mapping without a second manual entry. SHA-1/Base64 and BCrypt `$2a$` are implemented; Whirlpool is not supported yet.

Extra registration values are only needed by servers requiring other columns on account creation, such as normal access `accessLevel = 0`. Existing restrictions still prevent overriding login/password or granting elevated privileges.

The game editor has three steps: settings, field mappings, and relations/statistics. It has no feature checkboxes or manual schema requirement list. Tables and columns are editable in mapping rows; checks are generated from the data used. Existing optional fields and hero/castle tables remain optional. Related keys and secondary ordering follow primary field renames while explicit key edits are preserved. Rankings follow direct level, PvP, PK and play-time fields. Key/value (EAV) data is supported for character lookup, but world rankings and hero lists are not; the interface and profile capabilities reflect this limitation. JSON character rescue is not enabled. Saving any driver to the catalog neither proves compatibility with a real database nor applies it to a connection. Verify and apply it separately in server connection settings.

## Extension point for modules (driver_extensions)

**Do not forget this when building modules.** If a module needs data from the game or login database (a premium-account table, a column with an end date, a table for its own payouts), do not add those fields to the general character fields. The module **declares** what it needs and the administrator **tells the driver** where that lives in their build.

1. In `module.json` the module adds the `driver_extensions` field:

```json
"driver_extensions": [{
  "key": "premium",
  "type": "game",
  "required": true,
  "title": {"ru": "Премиум-аккаунт", "en": "Premium account"},
  "description": {"en": "Where the premium end date is stored."},
  "fields": [
    {"name": "table", "kind": "table", "required": true, "label": {"en": "Premium table"}},
    {"name": "until_column", "kind": "column", "required": true, "label": {"en": "End column"}},
    {"name": "unit", "kind": "choice", "options": ["days", "seconds"], "label": {"en": "Unit"}}
  ]
}]
```

   `type` is `game` or `login` (which driver it belongs to). Field kinds: `table`, `column` (a database name, checked as an identifier), `text`, `number`, `choice` (needs an `options` list). Limits: up to 8 declarations, up to 12 fields each; `ModuleValidator` checks the shape when the module is inspected.
2. The driver editor (**Servers → Drivers**) shows a "For modules" section at the end of the last step with the fields of every installed module, even one that is not enabled yet. The answers are stored in the profile at `config.extensions.<module id>.<key>.<field>`. Empty answers are not stored; answers for modules that are not installed here are kept and travel with the driver on export and import.
3. **Enabling a module is checked.** With `required: true`, `ModuleManager::enable()` refuses to enable the module until every connected server of that type has the required fields filled in its driver. The administrator gets a plain message: which server, which driver, what is missing and where to go.
4. **At run time** the module reads the answers like this:

```php
$values = app(\App\Support\UniversalDrivers\DriverExtensionAccess::class)->values($gameServer, 'premium', 'premium');
// ['table' => 'account_premium', 'until_column' => 'premium_end', ...]
```

   The verified snapshot of that connection's driver is used; servers still on a built-in PHP driver use the catalogue driver of the same name (`l2j_mobius`, `rusacis`). A changed driver reaches the connection after "Apply driver".

**Security.** The values are data, not SQL. Use table and column names from `config.extensions` only through the Laravel query builder (`$connection->table($values['table'])`) and check that the table and columns exist before writing. Never build raw SQL from them. Writes into the game database follow the same rules as the rest of the CMS: the reward queue or a verified writer, not a query straight from a form.

**Code:** `app/Support/UniversalDrivers/DriverExtensionCatalog.php` (declarations, validation, cleaning), `DriverExtensionRequirements.php` (enable check), `DriverExtensionAccess.php` (run-time reads); profile shape — `ProfileValidator::extensions()`; manifest shape — `ModuleValidator::inspectDriverExtensions()`; tests — `tests/Feature/UniversalDrivers/DriverExtensionTest.php`.

## 0.53.11 audit — schema preflight hardening

**Status: experimental; production migration is not certified.** JSON profiles can no longer mark their accounts/characters primary table optional or omit identity and character-filter columns from mandatory schema checks. LoginServer ownership must refer to the selected login/ID field. Nine additional negative offline contract tests are included. Hiding a bundled profile now also removes it from new connection selectors.

Outstanding verification: MySQL 8.4 parity, PHPUnit/PHPStan, clean installation and upgrades, browser/Windows quality; EAV-based ranking, account-ID ownership mapping, modular driver extensions, and complete bundled-driver hide semantics are not complete. Existing PHP game drivers remain intact.

---

## Iteration 8 — CMS connection integration (0.53.10)

**Current status: experimental.** Catalog profiles are selectable for LoginServer/GameServer connections. New custom drivers require an actual MySQL schema probe before replacing connection settings. Existing RUSaCis/Mobius PHP drivers remain operational until the administrator explicitly chooses **Apply JSON and verify**. Failed validation preserves the previously applied profile.

A successful validation stores a connection-local JSON snapshot and SHA256 and binds it to the actual database credentials and target using keyed HMAC. Catalog edits never silently replace a running configuration; changed DB credentials require a fresh check. MySQL/MariaDB only, no arbitrary SQL/PHP, and only the supported SHA-1/Base64 or BCrypt `$2a$` password schemes.

**Deliberate limitations:** personal actions using a GameServer account-ID ownership mapping are disabled until the LoginServer-ID mapping is verified. The legacy Mobius-specific rescue writer is disabled for JSON-active profiles. Wallet, Donations and the game reward queue are unchanged. Actual game-client password acceptance requires separate checks.

**Migration:** `2026_10_25_020000_bind_universal_snapshots_to_connections.php` adds an HMAC fingerprint column to the CMS snapshot table only. It does not modify game databases or existing published migrations.

**Before production:** run PHPUnit, PHPStan, migrations, real MySQL parity, Windows quality and browser checks through Codex/CI. Do not migrate all production servers until the final acceptance stage.

---

## Earlier implementation notes (historical)

# Universal drivers — iterations 1–3 (JSON + read-only LoginServer/GameServer)

**Status: experimental and NOT enabled in the CMS.** The existing production PHP LoginServer/GameServer drivers, registration, password updates, character queries, castles and reward delivery are unchanged. There is no admin editor or import action yet. Separate read-only LoginServer and GameServer character adapters exist for isolated validation only; it is not wired into production resolvers. A valid profile file is not proof of compatibility with a game build.

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

Game profiles use `characters.owner`, `characters.fields` sources (`column`, `related`, `choice`, `eav`, `operator`), limited `relations`, `filters`, `rankings`, and `features`. Column/table values are validated as identifiers, never SQL expressions; read adapters bind data values. The optional `heroes` and `castle` schemas must not break the basic character list.

**Mapped behavior:** The experimental character reader now handles RUSaCis `herountil` OR active `heroes`, nullable or zero `deletetime`, and Mobius `karma`/`reputation` by chronicle/schema. Castle names, public statistics and account ownership authorization are not yet migrated. A valid JSON alone does not demonstrate parity.

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

Read-only LoginServer and character GameServer parity tests are included in this job. Actual MySQL execution is required before claiming parity; full statistics and castle parity belongs to iteration 4. Valid JSON is a structural contract, not proof of a working game login.

## Next steps

Iterations 2–3 add isolated read-only LoginServer and GameServer character queries on MySQL, still alongside the PHP drivers. Admin UI, built-in overrides/tombstones, imported custom profiles, and per-connection validated snapshots come later. `database.dialect` keeps the format structurally extensible for a future SQL Server/PTS adapter, but `sqlserver` is deliberately rejected in v1 and no SQL Server dependency is installed. No database migrations are required for this iteration.

## Iteration 2 — LoginServer read-only path

`app/Services/GameAccounts/UniversalLoginAccountReader.php` supplies `summary`, `inspect` and `diagnose` using validated LoginServer JSON profiles and an existing Laravel MySQL connection. All login values are query-bound. Optional account metadata can be absent; a missing access column yields `unknown`, not an invented `active` state. Mobius inspection fails closed if the configured email column is absent. No JSON-controlled SQL expressions, registration, password changes or production switching are introduced.

The disposable MySQL CI fixture job now installs locked dependencies and directly compares legacy and JSON-powered read-only results for RUSaCis, Mobius and Mobius Legacy, including missing accounts, wrong credentials, SQL injection as a login value, and missing optional fields. Only unique, test-owned databases are dropped on completion. No production server connection is needed or permitted.


## Iteration 3 — experimental read-only GameServer (0.53.5)

`app/Services/GameWorld/UniversalGameCharacterReader.php` accepts an existing Laravel MySQL `Connection`, a validated JSON profile and a trusted game-account identity. It never selects a server by itself, performs writes, or hooks into the production `GameWorldDriverResolver`. The caller remains responsible for verifying the CMS user's rights to the game account and character.

- `diagnose(Connection, profile)` reports missing required and optional tables and columns.
- `charactersForAccount(Connection, profile, identity, limit, chronicle)` filters by the configured owner, applies parameter-bound filters, reads direct/choice/related/EAV mappings, then orders by level and name (1–100 results).
- Built-in Mobius `karma/reputation` chronicle selection and RUSaCis hero flag (`herountil` or an active hero record) are supported for character rows.
- Related/EAV mappings use bounded batched SELECTs rather than raw imported SQL. Duplicate one-to-one records and duplicate EAV values fail closed. Scans exceeding 1,000 character rows per account fail explicitly rather than returning a misleading partial result.

**Not implemented yet:** public rankings, full hero/castle sections, production resolver switching, writes, the editor or driver importing. The new adapter does not itself authorize a CMS user.

`tests/Integration/UniversalDrivers/mysql-game-character-parity.php` is invoked by `mysql-fixtures.php` and compares this read path with the existing PHP game drivers on disposable local MySQL fixtures. It tests account isolation, bound hostile identity strings, hero/reputation mappings, EAV, and ambiguous data. CI requires Composer dependencies from the approved lock and MySQL 8.4. Until executed, the presence of these tests is not a successful compatibility verification.


## Iteration 4 — experimental GameServer rankings, heroes and castles (0.53.6)

`app/Services/GameWorld/UniversalGameWorldReader.php` supplies standalone **read-only** `ranking`, `heroes`, `castleOwners`, `capabilities`, and `diagnose` on an existing Laravel MySQL connection. It never chooses a DSN, writes to the game database, or modifies the production `GameWorldDriverResolver`.

- Level/PvP/PK/play-time rankings sort/filter through whitelisted JSON column mappings with Query Builder bindings; clan names follow the configured one-to-one join. Duplicate character identities fail closed.
- The hero list follows profile-defined character/hero relations, using RUSaCis active/played filtering and Mobius hero record fields. The RUSaCis character hero flag still combines `herountil` with eligible hero records.
- Castle ownership follows the configured clan/castle/leader relations. Mobius uses the DB name; RUSaCis uses a reusable translated nine-castle catalog with safe unknown-ID fallback.
- Optional `features.heroes.count_column`/`claimed_column` and `features.castles.clan_relation`/`leader_relation`/`clan_columns`/`leader_name_column` extend the existing format-v1 schema without breaking previously exported v1 profiles. Arbitrary SQL or PHP in JSON remains prohibited.
- If optional `heroes` or `castle` tables are unavailable, only those respective capabilities are disabled; core rankings continue when mandatory tables are valid.

**Explicit limitation:** ordering rankings by EAV or remote-table fields is not yet implemented; the reader rejects these configurations rather than producing incorrectly ordered lists. MySQL parity with existing RUSaCis/Mobius drivers is covered by `tests/Integration/UniversalDrivers/mysql-game-world-parity.php`, called from the disposable `mysql-fixtures.php` job, but parity is **not verified** until that job executes successfully. No production switching, SQL Server/PTS adapter, write operation, admin UI, CMS migration or dependency change was made.

## Iteration 5 — experimental LoginServer writes (0.53.7)

`app/Services/GameAccounts/UniversalLoginAccountWriter.php` now prepares passwords using an allowlisted JSON profile scheme (Mobius/Legacy SHA-1 Base64 or RUSaCis BCrypt `$2a$` at a specified cost), creates accounts and changes passwords on an **already connected MySQL** Laravel `Connection`. It is **not wired into any production resolver, route, or CMS game account workflow**.

- `preparePassword` and `acceptsCredential` work offline. Keep the original salted BCrypt proof for registration recovery; never generate another salt on retry.
- `create` binds only configured login/credential/email columns and optionally declared scalar `accounts.create.fixed_values` (which cannot override identity fields or grant elevated access). Creation is rejected without capability, required columns, or a **unique login index**. A unique-key exception is left to the existing gateway and its pending/active/failed UUID recovery workflow.
- `changePreparedPassword` performs compare-and-set on the expected stored hash; retrying with the **same immutable target hash** is safe after a lost response, while a conflicting third-party password change fails closed. The future authorized caller must persist the prepared proof and verify user ownership. The current player account password route remains on the legacy driver.
- Offline checks: `php tests/Integration/UniversalDrivers/validate-write-profiles.php`. The disposable MySQL CI suite additionally covers insertion, duplicate-key protection, prepared-credential readback, repeated password writes, stale proof conflicts and missing unique indexes in `mysql-login-write-parity.php`. **Tests in CI are not proof of parity until they actually pass on real MySQL; hash format does not prove login acceptance by the real game server.**

No database migrations, PHP/Composer dependency updates, PTS adapter, live driver switching, import/editor UI or new CMS idempotency journal is included.

## Iteration 6 — local profile catalog and verified snapshots (0.53.8)

`DriverProfileCatalog` overlays bundled JSON in `driver-profiles/bundled` with owner-controlled rows in the **KaevCMS database** (`universal_driver_profiles`). Built-in files remain release-owned; editing creates a local same-ID override and deleting an unused bundled profile creates a persistent tombstone. `restoreBuiltin` returns to the current release's bundled JSON. Connected profiles cannot be deleted (checked against login/game servers and snapshot references). JSON import allocates a new ID/display name on conflicts and never silently overwrites existing entries. Export contains only validated JSON, never database connection credentials or secrets.

`ConnectionProfileSnapshots` keeps the last **successfully externally verified** JSON configuration per connection in `universal_driver_connection_snapshots` (format version, SHA256, driver identity and timestamp). Its API requires a real connection/schema verifier callback; a failed check does not overwrite the previous snapshot. Stored snapshot integrity and connection identity are rechecked on load. Saving the catalog does not affect a running connection.

**No production switch or public CRUD route is enabled in iteration 6.** Hooking up server configuration checks and the authorized/audited administration UI is scheduled for iterations 7–8. Existing PHP drivers remain active and existing connections have no JSON snapshot yet. The additive `2026_10_25_010000_create_universal_driver_catalog_tables.php` migration creates only two CMS-local tables; no game database or existing server fields are changed. Feature tests are in `tests/Feature/UniversalDrivers/ProfileCatalogTest.php` and require locked Composer dependencies plus an isolated CMS test database.


## Iteration 7 — driver administration UI (0.53.9)

**Servers → Drivers** provides separate LoginServer/GameServer lists and a structured offline editor: name/ID, tables and columns, relations, account and character mappings (including EAV/column choices), filters, rankings, heroes, castles and supported capabilities. JSON import/export is limited to 256 KiB. No game database is contacted while creating, editing or importing.

Bundled profiles can be edited using local CMS-owned overrides, hidden, restored to release defaults or exported. Profiles referenced by active connections or verified snapshots cannot be deleted. Stale concurrent edits fail instead of overwriting a newer profile. Editing requires `servers.manage`; browsing/export requires `servers.view`. Audit records contain action/identity only.

**Not enabled:** new JSON profiles are not yet selectable in the regular LoginServer/GameServer connection forms. Server-side schema verification and the production handover are iteration 8. Import validity does not prove game runtime compatibility; full tests need PHPUnit/browser/MySQL in an isolated environment.
