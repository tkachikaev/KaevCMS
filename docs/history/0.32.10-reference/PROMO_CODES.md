# Promo codes

KaevCMS 0.26.0 includes the bundled `promo-codes` module. The module never writes to a character inventory. A successful activation creates a normal server-bound grant through `RewardInventoryService`, and the player transfers the reward from the existing web inventory later.

## Installation

The module is shipped in:

```text
modules/promo-codes/
```

Open **Control panel → Modules** as an owner and choose **Install and enable**. KaevCMS applies the four module migrations before loading its routes or PHP code. Disabling the module preserves all promo codes, activations and granted inventory items.

## Promo-code fields

- code: 4–64 characters; Latin letters, numbers, hyphens and underscores; stored in uppercase;
- GameServer: every activation and reward is permanently bound to this server;
- optional start and end date/time, edited with native calendar controls;
- total activation limit; `0` means unlimited;
- per-account limit for one KaevCMS user account; minimum `1`;
- one to one hundred unique simple rewards using compact `item_id + amount` rows; one row is shown initially and more are added on demand;
- enabled/disabled state.

Names are not duplicated inside each promo code. The shared localized catalog in `lang/{locale}/items.php` supplies names to the promo-code list, activation history and web inventory; administrators continue entering the technical `item_id`. See `docs/GAME_ITEMS.md`.

A GameServer cannot be changed after the first successful activation. The code, dates, limits, state and future reward set may still be edited. The activation journal keeps the original code, account email, GameServer and the exact inventory grant, so later edits do not rewrite history.

## Activation transaction

Activation is serialized by a database row lock on the promo code:

1. normalize and find the code;
2. lock the promo-code row;
3. check enabled state, dates, total limit and the authenticated CMS-account limit;
4. create one activation row with a unique request UUID;
5. grant all configured items through `RewardInventoryService` with an immutable activation-based grant key;
6. link the activation to the resulting inventory grant and increment the activation counter;
7. commit all changes together.

Repeating the same request UUID returns the existing activation and cannot grant the reward twice. Concurrent requests for the same code are serialized, so total and per-account limits cannot be overrun by simultaneous clicks.

## Administration and audit

Owners can create, edit, enable, disable and delete codes from the working list. An unused code is removed permanently. A code with activation history receives a soft-delete marker: it can no longer be activated and disappears from the list, while activation history, granted inventory items and audit data remain available. Administrators can view the module and activation journal in read-only mode. Editors have no module access.

The journal shows:

- activation time;
- code snapshot;
- CMS account and retained email snapshot;
- GameServer;
- exact granted items;
- inventory grant identifier.

Create, update, enable, disable and delete actions are written to the common KaevCMS audit log.

## GameServer deletion

A GameServer referenced by an active promo-code configuration or activation history cannot be deleted. Deleting an unused promo code removes that configuration dependency. Activation history continues to block deletion because its rewards and journal records must remain connected to the original server.
