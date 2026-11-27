# Voting

Voting connects one KTreon project to KaevCMS. A player selects an owned game account and server before voting. Confirmed vote events can grant one reward bundle per vote to the site's Web Inventory for the selected server; the player then transfers items using the regular inventory workflow.

## Installation and connection

1. As owner, enable Voting in **Control panel → Modules**. On existing installations, approve the new module version and apply pending module migrations if shown.
2. In KTreon, open **My projects → Project → Voting integration**. Create a key with both `vote-intents:create` and `vote-events:read` permissions. Copy the project ID and the key (shown only once).
3. In **Voting → Providers → KTreon**, enter the ID and key, enable the provider and save.
4. Select **Check connection**. It reads at most one event over HTTPS without creating a vote, moving the saved cursor or issuing a reward. It verifies read access only; invitation creation and reward delivery must be tested separately.

The provider and player access are disabled by default. Only owners and administrators manage voting settings; auditors have read-only access. The API key is encrypted using the CMS `APP_KEY` and is never displayed again. Leaving the key field blank preserves the current key. Changing the project clears the previous key unless a new one is supplied; once invitation bindings or processed events exist, or the cursor has advanced, changing the project is blocked. Rotating the key of the same project is supported.

## Rewards and status policy

In **Voting → Rewards**, select a game server linked to a LoginServer, add up to 100 distinct item IDs (1–2147483647) and quantities (1–2000000000 per item), enable the bundle and save. Item names and icons are previewed using the CMS item catalogue. A nonempty bundle is required to enable rewards. The recipient is bound before the invitation is created. The reward bundle is fixed when the vote is first processed, not when the invitation is opened. Editing a bundle does not change existing vote snapshots.

| KTreon status | Reward behavior |
| --- | --- |
| Pending | Wait for confirmation (default), or grant immediately if explicitly configured |
| Accepted | Grant once if eligible and not already granted |
| Rejected or revoked | Do not grant; retain already issued items |

Immediate rewards are risky: a vote can subsequently be rejected. Policy changes apply to new status events, not replays of already processed events. A missing or ineligible recipient or an unconfigured reward at first processing is recorded without retroactive issuance. Opening an invitation or voting directly on KTreon without a CMS invitation does not earn a CMS reward.

## Opening player access

On an isolated test installation, verify invitation creation, status changes, Web Inventory delivery and duplicate-event replay. Check that the CMS scheduler is running. Then in **Voting → Providers → KTreon → Player access**, confirm testing and open access. Players select their own active game account and server in the account's Voting page, sign in on KTreon and complete the vote there.

Closing player access or disabling the provider stops new invitations but still processes existing invitations while the module remains enabled. Disabling the entire module stops synchronization. Rotating the key, rejected credentials or a decryption failure closes player access. After resolving the issue, check the connection again and explicitly reopen access.

## Synchronization and history

The existing CMS scheduler runs `kaevcms:voting-sync` every five minutes (up to 100 events per run); no dedicated worker is needed. Owners and administrators may use **Synchronize now** in provider settings (up to five events per request). From the CMS root, a manual CLI run is:

```bash
php artisan kaevcms:voting-sync --pages=1
```

This processes one page of up to 25 events. Both manual methods respect retry delays. Provider settings show the last successful synchronization, error status and retry time. If processing a page fails, that page is rolled back and its cursor does not advance.

The **History** tab shows processed votes, game account, server, status, reward outcome, reason and reward snapshot. Filter by server, vote status, reward state or game login. Players see only their own history, even while new invitations are paused and the module is enabled.

## Troubleshooting and recovery

- Connection rejected: check project ID, publication, key permissions and HTTPS access to `ktreon.com` from the CMS server. Rotate a revoked key in KTreon and save the new one in CMS.
- Rate limited: wait until the retry time; do not repeatedly restart synchronization.
- No reward: check the vote's status and reason in History, the selected account's eligibility and the scheduler. Do not manually reset the cursor or issue a second reward simply because synchronization is delayed.
- Backups: preserve `APP_KEY` and a consistent backup of the entire CMS database, including invitation bindings, vote/event history and all inventory grant/item records. Restoring an older backup after items have been transferred to the game cannot by itself prevent duplicate delivery.
