# Referrals

An independent KaevCMS module for invitations and character-level rewards on each GameServer.

## Development stage: iteration 4

- Iterations 1–3: module schema, invitation links, protected cookie, standard/social registration, admin reward rules and audited invitation freezing.
- Bounded `kaevcms:referrals-scan --batch=30` command registered through the existing module Scheduler (`everyFiveMinutes`, overlap protection). Cron uses the existing `schedule:run`; no new daemon or Composer dependency.
- First GameServer participation is only confirmed when the supported `GameAccountGateway` actually returns a character for a newly completed CMS-created account belonging to the invitee and applicable to that GameServer's LoginServer. A shared LoginServer alone does **not** establish participation.
- Immutable per-invitation/per-server rule snapshots and one entitlement per side and level are created in a short CMS transaction. Levels already reached are marked `earned` and are never reset. No inventory grants occur in iteration 4.
- Batch checks distinguish new-server discovery from ongoing progress, honor `next_check_at`, stop after all snapshot levels are reached, and back off on unknown or failed server reads. `ExternalServerCooldown` and the native short-lived character cache protect the GameServer from repeated failed reads.
- Snapshot capture and progress updates recheck global/individual settings and frozen invitation status inside the write transaction. Cache locks and unique indexes prevent competing scans from creating duplicate snapshots. The same server lock ordering as the admin rule editor is used.
- Optional manual check: authenticated POST `/modules/referrals/check/{invite}/{server}` only for the inviter/invitee; a server-side five-minute cooldown is applied. The player-facing control will be added in iteration 6.
- The admin invitation journal now shows safe progress states, max confirmed level, last error category, and next check time. No private game account data or raw driver errors are displayed.
- Existing rule snapshots remain unchanged when administrators edit or remove the current rules. Module or server disable, checks pause, and invitation freezing halt scanning without deleting progress.

The optional maximum rewarded invitees and web inventory issuance are **not yet enforced**; these belong to iteration 5. Player dashboards and final admin UX belong to iteration 6. Testing is required in the full PHP/Composer environment and for concurrent workers against real MySQL/MariaDB before deployment.

The module's migrations are applied through **Admin → Modules**. No migration is added in iteration 4. `VERSION`, module version and Composer dependencies are unchanged.
