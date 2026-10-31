# Referrals

An independent KaevCMS module for invitations and character-level rewards on each GameServer.

## Development stage: iteration 7 — QA candidate, not a production release

- Iterations 1–3: module schema, invitation links, protected cookie, standard/social registration, admin reward rules and audited invitation freezing.
- Bounded `kaevcms:referrals-scan --batch=30` command registered through the existing module Scheduler (`everyFiveMinutes`, overlap protection). Cron uses the existing `schedule:run`; no new daemon or Composer dependency.
- First GameServer participation is only confirmed when the supported `GameAccountGateway` actually returns a character for a newly completed CMS-created account belonging to the invitee and applicable to that GameServer's LoginServer. A shared LoginServer alone does **not** establish participation.
- Immutable per-invitation/per-server rule snapshots and one entitlement per side and level are created in a short CMS transaction. Levels already reached are marked `earned` and are never reset. Inventory grants are implemented separately in iteration 5.
- Batch checks distinguish new-server discovery from ongoing progress, honor `next_check_at`, stop after all snapshot levels are reached, and back off on unknown or failed server reads. `ExternalServerCooldown` and the native short-lived character cache protect the GameServer from repeated failed reads.
- Snapshot capture and progress updates recheck global/individual settings and frozen invitation status inside the write transaction. Cache locks and unique indexes prevent competing scans from creating duplicate snapshots. The same server lock ordering as the admin rule editor is used.
- Optional manual check: authenticated POST `/modules/referrals/check/{invite}/{server}` only for the inviter/invitee; a server-side five-minute cooldown is applied. The player-facing control was added in iteration 6.
- The admin invitation journal now shows safe progress states, max confirmed level, last error category, and next check time. No private game account data or raw driver errors are displayed.
- Existing rule snapshots remain unchanged when administrators edit or remove the current rules. Module or server disable, checks pause, and invitation freezing halt scanning without deleting progress.

- Iteration 5: grants flow only through `RewardInventoryService`, with idempotent keys, independent recipients, freezes, optional invitee limits and the dedicated `kaevcms:referrals-grants` scheduled command.
- Iteration 6: the player page uses the bundled account layout and shared components for all account themes, including link copy, server-specific current rules, personal progress and inventory history. The admin now has a searchable reward journal, using the existing admin filter component. A runtime visibility resolver hides the account sidebar link until invitations and a participating server are enabled. No new CSS, fonts, palettes or external scripts were added.

Iteration 7 adds administrator guides `docs/ru/REFERRALS.md` and `docs/en/REFERRALS.md` plus regression coverage for frozen invitations under an optional invitee cap. Full Laravel/Browser/MySQL QA is still required before deployment. Browser-visible progress and rewards are never proof that game items were delivered to a character.

The module's migrations are applied through **Admin → Modules**. No new migration is added in iteration 7. `VERSION`, module version and Composer dependencies are unchanged.
