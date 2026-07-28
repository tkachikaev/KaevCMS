# Character rescue

KaevCMS can safely change a character's saved coordinates when the character is stuck and cannot return to a city normally. The operation writes directly to the game database and does not require a separate GameServer module.

## Configuration

Open **Game servers → Features**, select a GameServer, and configure **Return character to city**:

- enable the feature;
- enter a clear destination name;
- enter X, Y, and Z coordinates;
- set the minimum offline time;
- set the cooldown between successful rescues of the same character.

Settings are stored separately for every GameServer. The server owner must choose coordinates appropriate for the chronicle, geodata, and custom game build.

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

A game build with a different schema can provide its own `CharacterRescueGateway` without changing the administration or player interfaces.

## Operation journal

Every attempt receives a UUID and is recorded in `character_rescue_operations`. The journal stores the server, account, character, previous and target coordinates, result, and a safe failure code. Passwords and game-database connection details are never stored.
