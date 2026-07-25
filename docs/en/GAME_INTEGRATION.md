# Game integration

KaevCMS separates CMS data from external LoginServer and GameServer databases.

## Drivers

The current Mobius integration uses one driver with schema profiles instead of one duplicate driver per chronicle. Required game tables are `characters`, `clan_data`, and `heroes`; optional capabilities may use `castle`, `account_gsdata`, and `account_premium` when present.

Connection tests inspect required tables and compatible columns without displaying credentials. Server statistics are enabled per GameServer and use independent limits for level, PvP, PK, and play-time rankings.

## Accounts and characters

Players create linked game accounts through the configured LoginServer driver. Character pages query GameServer data with caching and short failure cooldowns. Account-profile avatars are separate from character avatars.

## Reward queue

KaevCMS stores rewards in its own web inventory first. A transfer writes an idempotent neutral payload to `kaev_reward_queue` in the selected GameServer database. The server owner chooses the consumer: Java plugin, script, SQL job, custom GameServer module, or another integration. KaevCMS does not modify game `items` tables or generate game object IDs.

## Assets

Item icons and character avatars are owner-provided files under public uploads. Server-specific assets take priority over common fallbacks.
