# Operations runbook

This runbook covers recoverable production incidents. Keep `.env`, the CMS database, public uploads, owner-maintained game assets, and update logs before changing files manually.

## Interrupted Windows update

The Windows updater writes a pending marker and moves obsolete files into a versioned backup before it changes the installed release marker. These files are part of recovery and must not be deleted while an update is incomplete.

1. Open the newest `storage/logs/update-*.log` and identify the first failed stage.
2. Keep `.env`, `storage/app/kaevcms/pending-update.json`, and `storage/app/kaevcms/update-backups/` unchanged.
3. Re-extract the same complete patch, or a newer supported patch, over the project with file replacement enabled.
4. Run the apply script shipped by that extracted release:

   ```powershell
   .\deployment\windows\apply-<version>.ps1
   ```

5. After a successful update, run:

   ```powershell
   .\deployment\windows\quality.ps1
   .\deployment\windows\browser-quality.ps1
   ```

Do not copy an old `VERSION`, apply script, `vendor`, or `storage` directory into a newer release. Do not remove the pending marker merely to bypass version validation. If the updater cannot resume, preserve the log and backup directory before restoring the whole project from a known-good backup.

## External LoginServer or GameServer database unavailable

Players receive a safe availability error; credentials, DSNs, SQL text, and stack traces are not shown. Existing CMS data remains available where the feature does not require the external database.

Check in this order:

1. Open **Administration → System information → External database diagnostics** and use **Check external databases**.
2. Compare database availability, last successful connection, and `SELECT 1` latency. An available game-service port does not prove database availability, and vice versa.
3. When the database is available, verify the active profile, driver capabilities, and required-table state. A missing optional table disables only its related capability.
4. For an unavailable database, use the safe error class and failure time, then verify host, port, database name, username, firewall, routing, and TLS in the server connection settings.
5. Run diagnostics again after the fix, then repeat the original operation. Do not create or edit account links or reward rows directly merely because the external database was temporarily unavailable.

The snapshot preserves the last successful schema state after a later network failure, helping distinguish schema changes from temporary outages. The diagnostic action does not modify the game database: it runs `SELECT 1` and reads table metadata only.

Never publish `.env`, database passwords, full DSNs, or raw Laravel stack traces when requesting support. Share the release number, operation time, safe error class/code, server ID, and the redacted diagnostic log.

## Reward queue `review` or `failed`

KaevCMS and the external consumer have separate states. In the CMS, `queued` only confirms that the immutable rows exist in `kaev_reward_queue`; it does not confirm character delivery. Consumer states are documented in `integrations/reward-queue/README.md`.

For `review`:

1. Do not release the reserved web-inventory items and do not deliver them manually.
2. Open **Administration → Reward queue**, filter by the affected GameServer, and read the localized diagnostic and recommended action.
3. Restore GameServer database connectivity. The live queue check distinguishes a missing table, an unsupported schema, and an unavailable database.
4. Search the external table by the exact `operation_uuid`/`request_uuid` and compare every `line_number`, target account, character, item ID, and amount.
5. Use **Check again** only after connectivity is restored. A complete match changes the CMS operation to `queued`; confirmed absence changes it to `failed` and returns the items.

For `failed`:

- `reward_queue_write_failed` means KaevCMS confirmed that no matching rows exist and returned the items to the web inventory. A new transfer is safe after the queue problem is fixed.
- `reward_queue_payload_conflict` remains `review`, not `failed`, because rows exist with a different immutable payload. Do not delete, alter, or deliver either side blindly.
- consumer-side rows with status `failed` still prove that the CMS write reached the external queue. They are investigated with `integrations/reward-queue/problematic.sql`; the CMS must not create a second operation with the same UUID.

Use the CMS operation UUID, reward-grant operation UUID, GameServer ID, inventory grant ID, and item composition to correlate the reward journals with the audit log. Preserve application logs and the external consumer log. Never include database passwords or raw connection strings in a support report.


## Administrator notifications

The scheduled scan normally runs every minute. To run it manually after fixing Scheduler or while validating a deployment:

```bash
php artisan kaevcms:notifications-scan
```

To remove records older than the configured retention period:

```bash
php artisan kaevcms:notifications-clean
```

A temporary override is available for maintenance, with a protected minimum of seven days:

```bash
php artisan kaevcms:notifications-clean --days=90
```

Clearing a notification list is not a repair action. Verify the current state in server diagnostics, queue health, module status or system information.

## Information to preserve for support

- KaevCMS version and update package filename;
- newest `storage/logs/update-*.log` or relevant redacted Laravel log section;
- operation UUID and GameServer/LoginServer ID where available;
- exact command or administrator action that failed;
- confirmation that `.env`, uploads, pending marker, and update backups were not removed.

## Game-account creation is stuck in `pending` or ended as `failed`

KaevCMS does not delete a local operation after an uncertain LoginServer result. The UUID, state, attempt count, and safe failure code are stored in `user_game_accounts`; the plaintext password is never retained. Do not insert an `accounts` row manually and do not change the state with SQL.

List `pending` operations that have not changed for at least five minutes:

```bash
php artisan kaevcms:game-accounts-recover --older-than=300
```

Verify one operation without repeating INSERT:

```bash
php artisan kaevcms:game-accounts-recover OPERATION_UUID
```

The command reads LoginServer and applies these safe rules:

- a matching row after a recorded write attempt becomes `active`;
- a missing row becomes `failed` and is not recreated without explicit permission;
- a password-hash or email mismatch remains `failed` with a conflict code and is never linked;
- an unavailable LoginServer remains `pending`.

Repeat INSERT only after the row is confirmed missing:

```bash
php artisan kaevcms:game-accounts-recover OPERATION_UUID --retry
```

Restore connectivity and verify the selected LoginServer before using `--retry`. Do not rotate `APP_KEY`: the encrypted proof of existing `pending/failed` operations would become unreadable. The command is not scheduled; an operator runs it only for a specific incident.
