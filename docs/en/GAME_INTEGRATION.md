# Game integration

KaevCMS separates CMS data from external LoginServer and GameServer databases.

## Drivers

The current Mobius integration uses one driver with schema profiles instead of one duplicate driver per chronicle. Required game tables are `characters`, `clan_data`, and `heroes`; optional capabilities may use `castle`, `account_gsdata`, and `account_premium` when present.

Connection tests inspect required tables and compatible columns without displaying credentials. Server statistics are enabled per GameServer and use independent limits for level, PvP, PK, and play-time rankings.

## Accounts and characters

Players create linked game accounts through the configured LoginServer driver. Character pages query GameServer data with caching and short failure cooldowns. Account-profile avatars are separate from character avatars.

## Reward queue

KaevCMS stores rewards in its own web inventory first. A transfer writes an idempotent neutral payload to `kaev_reward_queue` in the selected GameServer database. The server owner chooses the consumer: Java plugin, script, SQL job, custom GameServer module, or another integration. KaevCMS does not modify game `items` tables or generate game object IDs.

## Item catalog and assets

`GameItemCatalog` is the single API used by Promo Codes, Daily Rewards, Web Inventory, and reward journals. Static catalogs are stored in `resources/game-items` for `interlude`, `classic`, `high-five`, and `shine-maker`.

Each item entry maps an ID to a catalog English name and original icon key:

```json
{
  "907": {
    "icon": "accessary_necklace_of_anguish_i00",
    "name_en": "Necklace of Anguish"
  }
}
```

The selected profile is derived from the GameServer chronicle. Values such as `Classic 3.5 Tales Untold`, `High Five`, `h5`, `shineMaker`, and `Shine Maker` are normalized to their catalog profiles. Integer GameServer IDs are resolved through the stored server chronicle instead of silently falling back to Interlude.

Manual names from `lang/{locale}/items.php`, including per-server overrides, have priority. Russian falls back to the catalog English name when a manual translation is absent.

Image binaries are external to KaevCMS releases. Put original-name WebP files in the deduplicated shared pool:

```text
public/game-assets/items/{icon_key}.webp
```

The older profile path `public/game-assets/items/{profile}/{icon_key}.webp` remains a fallback, so the 0.33.6 Interlude pack is preserved. Numeric server/common overrides under `public/uploads/game-assets/items` keep priority.

Character packs live under `public/game-assets/characters/{profile}`. Empty hierarchies are included for all four profiles; server/common overrides under `public/uploads/game-assets/characters` keep priority.
