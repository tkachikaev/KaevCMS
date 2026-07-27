# Game integration

KaevCMS separates CMS data from external LoginServer and GameServer databases.

## Drivers

The L2J Mobius GameServer driver uses the canonical identifier `l2j_mobius`. LoginServer and GameServer drivers intentionally use the same identifier in their separate registries. The legacy C1/C4 LoginServer variant remains `l2j_mobius_legacy`.

KaevCMS uses one GameServer driver with schema profiles instead of duplicating a driver per chronicle. The inspector selects the legacy profile when `characters.karma` is used and the modern profile when `characters.reputation` is used. Required game tables are `characters` and `clan_data`. Complete `heroes` and `castle` tables enable the corresponding optional statistics sections; missing or incomplete optional tables do not disable character rankings.

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

Shared item icons are resolved by numeric ID and then by the profile catalog icon key:

```text
public/uploads/game-assets/items/common/{item_id}.webp
public/uploads/game-assets/items/common/{icon_key}.webp
```

Shared item icons use `items/common`, while server-specific overrides use `items/servers/{server_id}`. Character avatars are shared by all chronicles and use `characters/common/{race}/{gender}/{archetype}.webp`; server overrides use `characters/servers/{server_id}`. The old `public/game-assets` and chronicle-specific avatar folders are not used.
