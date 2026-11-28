# Character rescue

KaevCMS can safely change a character's saved coordinates when the character is stuck and cannot return to a city normally. The operation writes directly to the game database and does not require a separate GameServer module.

## Configuration

Open **Game servers**, choose **Configure** for the required GameServer, open the **Features** tab, and configure **Return character to city**:

- enable the feature;
- enter a clear destination name;
- enter X, Y, and Z coordinates;
- set the minimum offline time;
- set the cooldown between successful rescues of the same character.

Settings are stored separately for every GameServer. The server owner must choose coordinates appropriate for the chronicle, geodata, and custom game build. When enabled, the player button remains visible for both offline and online characters. An online character receives a clear logout notice instead of a write attempt.

## Execution rules

KaevCMS rechecks the game database immediately before the write. Rescue is allowed only when:

- the game account belongs to the signed-in user;
- the character belongs to that account;
- the character is not deleted and has no elevated `accesslevel`;
- `online` equals zero;
- the configured time has passed since `lastAccess`;
- the rescue cooldown has expired;
- the driver and `characters` table expose the required columns.

Only `x`, `y`, and `z` are changed. KaevCMS does not alter inventory, HP/CP/MP, effects, instance state, jail state, events, or other game restrictions.

## Driver capability

For L2JMobius, support is detected automatically through the `character_rescue` capability. The `characters` table must contain `charId`, `char_name`, `account_name`, `x`, `y`, `z`, `online`, `lastAccess`, `deletetime`, and `accesslevel`.

The native RUSaCis PHP driver uses the same columns except for the character identifier (`obj_Id`), and accepts `deletetime` equal to zero or NULL. Both supported schemas store `lastAccess` in milliseconds; unknown (NULL) online status or last access blocks rescue. Applied Mobius and RUSaCis JSON profiles also support rescue when the schema permits it. Custom profiles must map direct ID, name, owner (login), online (0 = offline), last_seen_at_ms, X/Y/Z, access_level and deletion_time fields; configure main-table filters for access level = 0 and deletion = 0 or 0/NULL. An InnoDB table with a unique ID is required. Save the profile and apply JSON with connection verification. Unsupported schemas remain read-only for rescue.

## Operation journal

Every attempt receives a UUID and is recorded in `character_rescue_operations`. The journal stores the server, account, character, previous and target coordinates, result, and a safe failure code. Passwords and game-database connection details are never stored.
