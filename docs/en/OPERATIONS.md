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

1. Open **Administration → System information** and the relevant LoginServer or GameServer connection screen.
2. Run the built-in connection test.
3. Verify host, port, database name, username, firewall rules, routing, and TLS settings.
4. Verify that the selected driver/schema profile matches the external database.
5. Restore connectivity, then repeat the original operation. Do not create or edit account links or reward rows directly merely because the external database was temporarily unavailable.

Never publish `.env`, database passwords, full DSNs, or raw Laravel stack traces when requesting support. Share the release number, operation time, safe error class/code, server ID, and the redacted diagnostic log.

## Reward queue unavailable or uncertain

A failed queue write keeps or restores the reward in the web inventory. An uncertain write is placed in `review` and must not be reissued manually before reconciliation.

1. Restore the GameServer database connection and verify `kaev_reward_queue` using the supplied installation SQL.
2. Open **Administration → Reward queue** and run reconciliation for the affected operation.
3. Use the operation UUID, GameServer ID, user, character, and status to correlate the CMS audit log with the external queue.
4. Do not delete a `review` delivery or grant the items manually until the queue result is known.

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
