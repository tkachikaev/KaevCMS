# Referrals 1.0.1

Invitations and character-level rewards for KaevCMS 0.57.0 or later. Rewards go to Web Inventory; players request any subsequent game delivery from there.

## Setup

1. Enable the module and apply its migrations in the administrator Modules section.
2. Enable invitations, configure each GameServer, and add reward milestones for the inviter and invitee.
3. Set the maximum rewarded friends per inviter and GameServer. New configurations default to three; an explicitly empty value means unlimited. This limits rewards, not registrations.
4. Enable the existing CMS Scheduler (`schedule:run` every minute). The module registers bounded scan and grant commands every five minutes, with overlap protection.

## Behavior

- A protected, HttpOnly invitation cookie lasts seven days by default. Only a newly registered CMS user is attached, including new users created through Social Auth. Existing-account sign-in never creates a referral.
- A qualifying game account must be newly provisioned through the CMS after accepting the invitation, belong to the invitee and use the corresponding LoginServer. Imported accounts do not qualify.
- Character levels are read through the configured game driver. Progress, retry backoff, cooldown and short-lived character caching protect external databases.
- The first confirmed character creates an immutable per-invitation/per-GameServer rule snapshot. Later rule edits do not rewrite existing entitlements. Multiple already-reached milestones may qualify together.
- Rewards are granted through RewardInventoryService with transactional audit, inventory idempotency and concurrent-state checks. The module does not write Wallet balances or character inventories directly.
- Disabling a server or module, pausing checks, or freezing an invitation prevents further processing without deleting history or previously granted rewards. Freezing requires an administrator reason.
- Administrators can see linked CMS accounts, filter the reward journal and manage milestones. Players see their invitation link, progress and own rewards. All bundled account themes support RU/EN and responsive layouts.

## Deployment boundaries

Use a supported game driver and confirm its character-level reads against the actual server schema. Configure and monitor the hosting Scheduler; larger backlogs may delay grants. A character deleted before its first successful scan cannot be observed by polling.

The Java-side game-delivery queue and its processor are managed by the game-server administrator. Their absence does not prevent Referrals from granting items to Web Inventory.
