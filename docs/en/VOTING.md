# Voting

The module configures a connection to the KTreon ranking platform. This version supports connection settings and API checks; player voting and automatic rewards are not available yet. Reward bundles can be configured in advance.

1. As owner, enable Voting in **Control panel → Modules**. Installation applies the module migrations.
2. In KTreon open **My projects → Project → Voting integration**, issue a key with `vote-intents:create` and `vote-events:read` permissions. Copy the project ID and key (the key is displayed only once).
3. In KaevCMS open **Voting → Providers → KTreon**. Enter the project ID and API key and save. The provider defaults to disabled.
4. Select **Check connection**. It reads at most one event over HTTPS without creating a vote or issuing a reward. Repeat the check after changing the project ID or key.

The key is encrypted with the CMS APP_KEY. Preserve APP_KEY during updates and recovery. A blank key field preserves the current project's key; changing the project ID removes the previous key. Never publish the key or share it with players. After rotating the key in KTreon, save the new key in the CMS.

Waiting for confirmation is the safer default. Immediate rewards risk rewarding votes which are later rejected. In this version both policies are stored only; no rewards are issued. Owners and administrators manage settings; auditors may view them without access to the key.

If the check fails, verify the project ID, key validity, project publication and HTTPS access to ktreon.com from the CMS server. Retry later if rate limited. A successful check confirms event-read access only, not reward delivery.

For Voting 0.3.0, an existing installation must update the module database in **Control panel → Modules**. Player access remains unavailable while reward delivery is not supported. After an invitation has been prepared, the connected project cannot be changed; rotating the key of the same project remains supported.

In **Voting → Rewards**, select a game server and enter the item IDs and quantities (up to 100 different items). Enable the bundle only when the server is linked to a login server. Save to apply the configuration. An auditor may view but not change it. The History tab displays processed votes; synchronization is not yet available. Changes affect votes at first processing, not previously saved reward snapshots.
