# Voting

The module configures a connection to the KTreon ranking platform. This version supports connection settings and API checks; player voting and automatic rewards are available after explicit activation.

1. As owner, enable Voting in **Control panel → Modules**. Installation applies the module migrations.
2. In KTreon open **My projects → Project → Voting integration**, issue a key with `vote-intents:create` and `vote-events:read` permissions. Copy the project ID and key (the key is displayed only once).
3. In KaevCMS open **Voting → Providers → KTreon**. Enter the project ID and API key and save. The provider defaults to disabled.
4. Select **Check connection**. It reads at most one event over HTTPS without creating a vote or issuing a reward. Repeat the check after changing the project ID or key.

The key is encrypted with the CMS APP_KEY. Preserve APP_KEY during updates and recovery. A blank key field preserves the current project's key; changing the project ID removes the previous key. Never publish the key or share it with players. After rotating the key in KTreon, save the new key in the CMS.

Waiting for confirmation is the safer default. Immediate rewards risk rewarding votes which are later rejected. The policy applies to newly processed status events; previously granted items are not reclaimed. Owners and administrators manage settings; auditors may view them without access to the key.

If the check fails, verify the project ID, key validity, project publication and HTTPS access to ktreon.com from the CMS server. Retry later if rate limited. A successful check confirms event-read access only, not reward delivery.

For Voting 0.5.0, an existing installation must update the module database in **Control panel → Modules**. Player access is closed by default. After an invitation has been prepared, the connected project cannot be changed; rotating the key of the same project remains supported.

In **Voting → Rewards**, select a game server and enter the item IDs and quantities (up to 100 different items). Enable the bundle only when the server is linked to a login server. Save to apply the configuration. An auditor may view but not change it. The History tab displays processed votes, reward status, recipient and reward snapshot, with filters for server, vote status, reward status and exact game account login. Players see only their own history, including when new invitations are paused, while the module remains enabled. Changes affect votes at first processing, not previously saved reward snapshots.

## Activation and scheduler

Configure rewards and verify the current connection. On a test installation verify voting, pending and accepted statuses, Web Inventory delivery and replay before opening player access. Use **Voting → Providers → KTreon → Player access** and explicitly confirm testing. Rotating the key, disabling the provider, rejected credentials or a key decryption failure closes access. After resolving the issue, check the connection again and explicitly reopen access. The existing CMS scheduler runs `php artisan kaevcms:voting-sync` every five minutes; no separate queue worker is required. A manual CLI run can use `--pages=1`. Owners and administrators may also use **Synchronize now** in the provider settings (up to five events per request). This does not bypass retry delays. Keep the Voting module enabled to continue processing already issued invitations even when new invitations are stopped. Disable the entire module to stop synchronization. The last successful synchronization and safe error status are displayed in provider settings. A failed page is retried without advancing its cursor. Rewards go to the site user's Web Inventory for the selected server, not directly into the game account. No rewards are given for merely opening a link or voting without a CMS invitation. Preserve the complete CMS database, including inventory grant and item records, in consistent backups; restoring an older backup after items were transferred to the game cannot by itself guarantee duplicate prevention.
