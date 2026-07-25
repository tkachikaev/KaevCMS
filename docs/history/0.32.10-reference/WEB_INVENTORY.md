# Web inventory and GameServer reward queue

KaevCMS stores rewards in its own database until a player selects a character on the same GameServer. A successful transfer writes neutral rows to `kaev_reward_queue` in that GameServer database.

KaevCMS does not insert into `items`, allocate `object_id`, patch GameServer sources, run a heartbeat or verify a bridge protocol. The GameServer administrator decides how pending queue rows are processed.

## Server isolation

Every grant, inventory item and transfer contains a `game_server_id`. A reward cannot be moved to another server. When only one server has rewards, the player interface hides the server switcher. With several servers, the inventory is separated into server tabs.

## Player flow

1. The player opens `/account/web-inventory`.
2. The player selects one GameServer when several are available.
3. The player selects up to 50 available rewards.
4. The player selects a character that belongs to one of their game accounts on that server.
5. KaevCMS creates one idempotent local transfer and temporarily reserves the inventory rows.
6. KaevCMS writes one `kaev_reward_queue` row per selected reward.
7. If the expected rows are confirmed, the local inventory items become `transferred` and the transfer becomes `queued`.
8. A confirmed write failure returns the rewards to `available`.
9. If the database result cannot be confirmed, the transfer becomes `review` and rewards remain reserved to prevent a possible duplicate submission.

Online characters are allowed because KaevCMS is not changing their inventory. The administrator-owned consumer decides when and how actual delivery is safe.

## Names and icons

Player-facing pages resolve `item_id` through `lang/{locale}/items.php` and do not expose a raw `Item #ID` label. Administrators still see the technical ID in journals. Existing rewards immediately use updated catalog names without a database migration. Icons are loaded independently from `public/uploads/game-assets/items`. See `docs/GAME_ITEMS.md`.

## Required GameServer table

Run this once in every participating GameServer database:

```text
integrations/reward-queue/install.sql
```

The minimum schema is documented in `integrations/reward-queue/README.md`. Additional administrator-owned columns and indexes are allowed.

Each row contains:

- `request_uuid` and `line_number` for idempotency;
- CMS GameServer and user identifiers;
- account and character identifiers and names;
- `item_id` and `amount`;
- a neutral processing status and administrator-owned error fields.

## Responsibility boundary

KaevCMS confirms only that the requested data reached `kaev_reward_queue`.

Actual item delivery may be implemented through:

- a GameServer plugin;
- an external service;
- a stored procedure or scheduled database event;
- manual administrator processing.

There is deliberately no universal `INSERT INTO items` script. Inventory tables, required fields and object ID allocation differ between distributions. Any consumer must be reviewed for the selected GameServer.

## Statuses in KaevCMS

- `pending` — local transfer is being prepared;
- `queued` — expected rows were confirmed in the GameServer queue;
- `failed` — the queue write definitely failed and rewards returned to the web inventory;
- `review` — the outcome could not be confirmed and rewards remain locked.

The status inside `kaev_reward_queue` belongs to the administrator-owned consumer and is not polled by KaevCMS.

## Administration

Owners and administrators can open the read-only **Reward queue** journal. It shows the player, GameServer, character, immutable item snapshots and whether KaevCMS confirmed the queue write. Editors cannot access it.

Useful integration files:

```text
integrations/reward-queue/install.sql
integrations/reward-queue/pending.sql
integrations/reward-queue/consumer-template.sql
integrations/reward-queue/mark-delivered.example.sql
integrations/reward-queue/mark-failed.example.sql
```
