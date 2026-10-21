# Game integration

KaevCMS separates CMS data from external LoginServer and GameServer databases.

## Drivers

KaevCMS includes two game-server families:

- **L2J Mobius** — identifier `l2j_mobius`. LoginServer and GameServer drivers intentionally use the same identifier in their separate registries. The legacy C1/C4 LoginServer variant remains `l2j_mobius_legacy`.
- **RUSaCis** — identifier `rusacis`, with its own LoginServer and GameServer drivers. See [RUSaCis GameServer](#rusacis-gameserver) below.

The L2J Mobius GameServer driver uses schema profiles instead of duplicating a driver per chronicle. The inspector selects the legacy profile when `characters.karma` is used and the modern profile when `characters.reputation` is used. Required game tables are `characters` and `clan_data`. Complete `heroes` and `castle` tables enable the corresponding optional statistics sections; missing or incomplete optional tables do not disable character rankings.

Connection tests and runtime queries use the same required-table and column contract without displaying credentials. Server statistics are enabled per GameServer and use independent limits for level, PvP, PK, and play-time rankings.

## Accounts and characters

Game-account creation is a durable operation with a unique UUID and `pending → active | failed` states. Before writing, KaevCMS verifies that the login is absent from LoginServer. After INSERT it reads `accounts` again and compares the login, driver-specific password hash, and normalized email. An already existing foreign account is therefore never linked automatically, even when the submitted values happen to match.

If LoginServer commits the INSERT but the connection ends with a timeout, KaevCMS keeps the local operation. A repeated request or manual recovery verifies the external row first and activates the link without a second INSERT. Only an encrypted driver proof is retained for safe retry; plaintext passwords are never stored, and the proof is removed after activation. `pending` counts toward the quota, while `failed` does not. No permanent background reconcile is scheduled.

Character pages use active links only and query GameServer data with caching and short failure cooldowns. Account-profile avatars are separate from character avatars. Recovery is documented in the [operations runbook](OPERATIONS.md#game-account-creation-is-stuck-in-pending-or-ended-as-failed).

## Reward queue

KaevCMS stores rewards in its own web inventory first. Every grant receives a server-generated operation UUID and keeps its GameServer ID and immutable item composition in the CMS audit trail. A character transfer creates another unique operation UUID and writes one neutral `kaev_reward_queue` row per item. `(request_uuid, line_number)` and the CMS request token prevent duplicate transfers and reject replay with a different target or item selection.

CMS states are `pending → queued | review | failed` and `review → queued | review | failed`. `queued` confirms only that the complete payload reached the external queue. The consumer-owned rows use `pending → processing → delivered | failed`, with direct `pending → delivered | failed` allowed for simple/manual consumers. KaevCMS does not poll or overwrite those consumer states. The full contract and safe SQL templates are in `integrations/reward-queue/README.md`; incident handling is in the [operations runbook](OPERATIONS.md#reward-queue-review-or-failed).

The server owner chooses the consumer: Java plugin, script, SQL job, custom GameServer module, or another integration. KaevCMS does not modify game `items` tables or generate game object IDs.

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

Image binaries are owner-managed runtime data under one canonical root:

```text
public/uploads/game-assets
```

KaevCMS does not include item icons or character artwork. Full, patch, cumulative, and shared-hosting release archives do not include the contents of this directory, and updates do not overwrite it. WebP is recommended; PNG, JPG, and JPEG are also supported. The old `public/game-assets` directory and chronicle-specific image folders are not used.

### Item icons

Shared item icons are resolved by numeric ID and then by the profile catalog icon key:

```text
public/uploads/game-assets/items/common/{item_id}.webp
public/uploads/game-assets/items/common/{icon_key}.webp
```

Examples:

```text
public/uploads/game-assets/items/common/57.webp
public/uploads/game-assets/items/common/etc_adena_i00.webp
public/uploads/game-assets/items/common/armor_t45_g_i00.webp
```

A server-specific override has priority:

```text
public/uploads/game-assets/items/servers/{server_id}/{item_id}.webp
public/uploads/game-assets/items/servers/{server_id}/{icon_key}.webp
```

Item catalogs stay chronicle-specific (`interlude`, `classic`, `high-five`, `shine-maker`), but the icon files are one shared pool.

### Character avatars

Character avatars are shared by all chronicles and use `race/gender/archetype`:

```text
public/uploads/game-assets/characters/common/{race}/{gender}/{archetype}.webp
```

Example full path:

```text
public/uploads/game-assets/characters/common/human/female/mage.webp
```

Supported files inside `characters/common`:

```text
human/male/warrior.webp      human/male/mage.webp
human/female/warrior.webp    human/female/mage.webp
elf/male/warrior.webp        elf/male/mage.webp
elf/female/warrior.webp      elf/female/mage.webp
dark_elf/male/warrior.webp   dark_elf/male/mage.webp
dark_elf/female/warrior.webp dark_elf/female/mage.webp
orc/male/warrior.webp        orc/male/mage.webp
orc/female/warrior.webp      orc/female/mage.webp
dwarf/male/default.webp      dwarf/female/default.webp
kamael/male/default.webp     kamael/female/default.webp
ertheia/male/warrior.webp    ertheia/male/mage.webp
ertheia/female/warrior.webp  ertheia/female/mage.webp
sylph/male/default.webp      sylph/female/default.webp
fallback/male/default.webp   fallback/female/default.webp
fallback/neutral/default.webp
```

For example, the neutral fallback is `characters/common/fallback/neutral/default.webp`. A server-specific override uses `characters/servers/{server_id}/{race}/{gender}/{archetype}.webp`. Resolution order:

1. Server-specific image.
2. Shared image from `characters/common`.
3. Built-in text fallback.

Use square images with consistent dimensions.

## RUSaCis GameServer

Independent RUSaCis schema, characters, online statistics, PvP/PK/level/play-time rankings, active heroes and castle owners. `characters.obj_Id`, `heroes.char_id` and nullable `deletetime` are handled by RUSaCis-specific code. Character rescue remains intentionally disabled until the live GameServer confirms `lastAccess` time units. Reward delivery still uses only the neutral queue `kaev_reward_queue`.
