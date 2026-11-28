# KaevCMS universal drivers

## Overview and boundaries

Use **Servers → Drivers** to create, duplicate, import and export LoginServer or GameServer JSON profiles. Profiles describe data mappings and capabilities, never DB addresses, passwords, arbitrary SQL or PHP. Only MySQL/MariaDB is supported. MS SQL/PTS is **not implemented**.

**Mobius, RUSaCis, Lucera 2 and custom drivers use one JSON profile engine.** Existing connections with bundled identifiers continue using the shipped profile without reconfiguration. Editing a catalog entry does not switch an active server. Select and verify/apply a custom or edited profile explicitly. Account creation and password changes require a unique login index and a password column large enough for the hash. The applied copy has an immutable SHA256 digest and HMAC binding to connection credentials. Changing those settings or editing the profile requires a fresh verification/application. After changing LoginServer database credentials, reapply JSON for any GameServer inheriting that connection as well. CMS does not fall back to a bundled profile when verification fails; GameServers with separate database credentials are unaffected by this change.

## Lucera 2 C6 and Classic

Select the bundled **Lucera 2** profiles for LoginServer and GameServer. When the schemas are separate, configure a separate GameServer connection. Verify and apply each profile. The LoginServer profile uses Whirlpool/Base64 for `PasswordHash=whirlpool2`. If your server uses another supported format, choose it in the editor and reapply the profile. Match the CMS game login and password rules to the Lucera configuration.

The LoginServer profile supports account creation and password changes. The GameServer profile reads characters, active subclasses, online counts and level/PvP/PK/play-time rankings. Heroes, castles and character rescue are not enabled in the bundled Lucera 2 profile. The stock character table uses MyISAM; the rescue writer requires a transactional character table and verified last-seen mapping. Compatibility was tested against the C6 and Classic SQL schemas; game-client sign-in and character creation require testing on the target server.

## LoginServer editor

First choose display name/ID, charset/collation, a supported game password format and capabilities. Then map account table and login/password/optional columns, registration constants and module fields. Lookup-only profiles can read account summaries without creation/password changes; the password format and column are optional for them. Registration and account-area functions requiring the complete LoginServer capability set remain unavailable on such connections. Account writes require a **unique login index** and a long enough password column in the actual MySQL table. Login columns must store strings; numeric columns cannot represent logins.

Allowed schemes: SHA-1/Base64, Whirlpool/Base64 (Whirlpool2), BCrypt `$2a$`, SHA-1/MD5/SHA-256/Whirlpool hex. Game-client acceptance, retries after ambiguous password writes and server-specific registration login rules require separate testing; arbitrary regex or custom code in JSON remains disallowed.

## GameServer editor

The basic character fields are open initially. The owner, ID and name share a primary table. For each related field, select whether a missing record keeps or excludes the character. Hero and castle link columns can be configured in their respective sections.

Three steps cover settings, character mappings, and relations/statistics. Online counts use `characters.fields.online` when it maps to a direct column. An applied snapshot overrides the bundled counter definition. Primary ranking metrics support direct columns or mapped key/value rows; secondary sorting is limited to direct columns. Personal owner mapping by internal Account ID remains out of scope. Character rescue supports verified JSON profiles with direct coordinate and safety mappings; see [Character rescue](CHARACTER_RESCUE.md). Reward queues, Wallet and Donations are unchanged.

### Related fields

For a field from another table, a `left` relation retains the character when no matching row exists and returns an empty field value. An `inner` relation requires a matching row; EAV requires a row for each mapped key. The stored value itself may be NULL. Required relations restrict account characters, rankings, heroes and dashboard character totals consistently. An inner relation's table and mapped columns must be required in the schema requirements. Feature-only hero and castle relations do not exclude ordinary characters. Character rescue is disabled for required related records because coordinate writes do not lock related tables. The online counter still counts connected characters using the online field.

### Ranking and filter sources

Rankings display the primary table and column from character mappings. When stored in the main character table, experience is the recommended tie-breaker for level; level for PvP/PK/play time. No secondary metric does not disable a ranking. Name is the final tie-breaker. For key/value primary metrics, `ranking.column` names the mapped integer value column. Text columns do not qualify for numeric rankings. Fields sharing a relation and key are read from a single matching row. Ambiguous keys never multiply ranking rows: required mappings exclude them; optional mappings yield empty values.

Filters follow “Table → Column → Condition → Value”. Another table requires both link columns, for example `characters.obj_Id ↔ character_access.char_id`. Correlated `EXISTS` avoids duplicate characters and excludes characters without a matching record. Conditions sharing a table and both link columns must match the same record, including EAV key/value conditions.

All filters combine with AND and affect public lists, account characters, and the administrator dashboard character count. Thus `accesslevel = 0` also hides GMs in their account. `zero_or_null` takes no value. Profiles may add `filters[].table` and `filters[].link = {local_column, foreign_column}`; omitted `table` retains the legacy primary-table source. Older CMS versions may reject the new keys; import these profiles into a version supporting related filters. Activation requires both tables and all filter/link columns. Queries never modify game data.

## Database encoding

The profile's **Charset** and **Collation** fields are editable on the first step for both server types. **An applied JSON snapshot takes precedence over the connection's original charset setting.** Reapply the profile to update an active server; connections without applied snapshots retain existing connection settings.

## Read-only activation preflight

Checks verify connectivity, required tables/columns, and mapping dependencies. For account writes the checker requires a unique login index and a sufficient password column. Unavailable rankings, heroes, castles and character rescue are reported separately without blocking activation when the required schema and filters pass verification. The editor does not make coordinates or fields used only by heroes or castles mandatory for the entire connection. Fields also needed for identity, core mappings or filters remain required. Missing optional related values are omitted or null without hiding characters. Missing tables or columns explicitly declared required still block activation. Character owner column types must match the declared identity (string login or integer ID). A saved but unapplied custom profile is not an active connection: stored-connection diagnostics report that verification/application is required. These are **structural checks only**, not evidence that all live game operations will work. The game database is never modified by preflight.

## Module `driver_extensions`

Each module declares its fields in `module.json`. In the editor the admin maps one table (`kind: table`) and relevant columns per extension group; split multi-table mappings into separate groups. Module enablement checks required values and actual table/column existence. Runtime reads verify the mappings again to fail closed if the database changes. Custom JSON profiles require a **verified applied snapshot**, not a catalog draft; bundled connections without a snapshot may use the unmodified shipped profile.

Modules must still enforce permissions, ownership, business constraints and use bound query-builder queries. Valid table names do not grant write authority.

## QA status

Offline checks: `validate-profiles.php`, `validate-write-profiles.php`, `validate-editor-drafts.php`, `validate-schema-preflight.php` under `tests/Integration/UniversalDrivers`. Remaining acceptance gates are disposable MySQL/MariaDB parity, PHPUnit/PHPStan, Playwright, clean install/update (Windows/VDS/shared), and real game-client checks.

To run the disposable MySQL/MariaDB fixtures locally with Composer dependencies installed:

```powershell
$env:KAEV_UNIVERSAL_MYSQL_TEST = "1"
$env:KAEV_MYSQL_TEST_HOST = "127.0.0.1"
$env:KAEV_MYSQL_TEST_USER = "root"
$env:KAEV_MYSQL_TEST_PASSWORD = Read-Host "Local test MySQL password"
php tests/Integration/UniversalDrivers/mysql-fixtures.php
```

Use a dedicated local test server and an account permitted to create and drop temporary databases. This check does not establish compatibility with the actual game client.
