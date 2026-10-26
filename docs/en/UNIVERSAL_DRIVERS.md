# KaevCMS universal drivers — current guide (0.54.3 QA candidate)

## Overview and boundaries

Use **Servers → Drivers** to create, duplicate, import and export LoginServer or GameServer JSON profiles. Profiles describe data mappings and capabilities, never DB addresses, passwords, arbitrary SQL or PHP. Only MySQL/MariaDB is supported. MS SQL/PTS is **not implemented**.

**Legacy Mobius and RUSaCis PHP drivers remain available.** Editing a catalog entry does not switch an active server. Select the JSON driver in server settings and explicitly verify/apply it. The applied copy has an immutable SHA256 digest and HMAC binding to connection credentials. Changing those settings or editing the profile requires a fresh verification/application.

## LoginServer editor

First choose display name/ID, charset/collation, a supported game password format and capabilities. Then map account table and login/password/optional columns, registration constants and module fields. Lookup-only profiles can now disable creation/password changes. Account writes require a **unique login index** and a long enough password column in the actual MySQL table.

Allowed schemes: SHA-1/Base64, BCrypt `$2a$`, SHA-1/MD5/SHA-256/Whirlpool hex. Game-client acceptance, retries after ambiguous password writes and server-specific registration login rules require separate testing; arbitrary regex or custom code in JSON remains disallowed.

## GameServer editor

Three steps cover settings, character mappings, and relations/statistics. Online counts use `characters.fields.online` when it maps to a direct column. An applied snapshot overrides the legacy counter definition. EAV rankings, rankings sorted by remote fields, personal owner mapping by internal Account ID and the old Mobius-only rescue writer remain out of scope. Reward queues, Wallet and Donations are unchanged.

### Ranking and filter sources

Rankings display the primary table and column from character mappings. Experience is the recommended tie-breaker for level; level for PvP/PK/play time. No secondary metric does not disable a ranking. Name is the final tie-breaker. Rankings still require direct columns in the primary character table.

Filters follow “Table → Column → Condition → Value”. Another table requires both link columns, for example `characters.obj_Id ↔ character_access.char_id`. Correlated `EXISTS` avoids duplicate characters and excludes characters without a matching record. Conditions sharing a table and both link columns must match the same record, including EAV key/value conditions.

All filters combine with AND and affect both public lists and account characters. Thus `accesslevel = 0` also hides GMs in their account. `zero_or_null` takes no value. Profiles may add `filters[].table` and `filters[].link = {local_column, foreign_column}`; omitted `table` retains the legacy primary-table source. Older CMS versions may reject the new keys; import these profiles into a version supporting related filters. Activation requires both tables and all filter/link columns. Queries never modify game data.

## Database encoding

The profile's **Charset** and **Collation** fields are editable on the first step for both server types. **An applied JSON snapshot takes precedence over the connection's original charset setting.** Reapply the profile to update an active server; legacy PHP drivers retain existing connection settings.

## Read-only activation preflight

Checks verify connectivity, required tables/columns, and mapping dependencies. For account writes the checker requires a unique login index and a sufficient password column. Advertised game rankings, heroes and castles undergo additional basic mapping checks. These are **structural checks only**, not evidence that all live game operations will work. The game database is never modified by preflight.

## Module `driver_extensions`

Each module declares its fields in `module.json`. In the editor the admin maps one table (`kind: table`) and relevant columns per extension group; split multi-table mappings into separate groups. Module enablement checks required values and actual table/column existence. Runtime reads verify the mappings again to fail closed if the database changes. Custom JSON profiles require a **verified applied snapshot**, not a catalog draft; bundled legacy PHP drivers may use the shipped catalog template.

Modules must still enforce permissions, ownership, business constraints and use bound query-builder queries. Valid table names do not grant write authority.

## QA status

Offline checks: `validate-profiles.php`, `validate-write-profiles.php`, `validate-editor-drafts.php`, `validate-schema-preflight.php` under `tests/Integration/UniversalDrivers`. Remaining acceptance gates are disposable MySQL/MariaDB parity, PHPUnit/PHPStan, Playwright, clean install/update (Windows/VDS/shared), and real game-client checks. **0.54.3 is a QA candidate, not production certified.**

Prior iteration notes: [0.54.0 archive](history/UNIVERSAL_DRIVERS_0.54.0.md).
