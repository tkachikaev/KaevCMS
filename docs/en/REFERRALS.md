# Referral system

The `referrals` module lets players invite friends and receive items when the invited player's characters reach configured levels. Rewards go to the **KaevCMS Web Inventory**, not directly to a character. Players can deliver items to a character later using the existing inventory workflow.

> This guide describes Referrals 1.0.0. Complete installation, registration, game-level and reward tests against an isolated MySQL/MariaDB database before enabling it on a live site. The presence of the module in a source archive does not prove production readiness.

## Enable the program

1. Open **Administration → Modules → Referrals**. Install and enable the module. Apply its migrations through the normal Modules interface; visiting a player page never applies migrations.
2. Open the **Referrals** administration page and enable invitations. The invitation lifetime defaults to **7 days**, configurable from 1 to 30 days.
3. Select a GameServer and enable participation. Enable reward rules for the **Inviter**, the **Invitee**, or both independently.
4. Add rules to each enabled side: **character level → item ID → quantity**. A level can occur only once per side and GameServer, with up to 30 levels per side. Each GameServer has independent settings.
5. Save the rules. **Invite a friend** appears in the player account when the program is enabled and at least one server has active rules.

An item ID not found in the built-in catalogue can still be used; verify it against your game build. A missing catalogue icon or name does not by itself make the item ID invalid.

## Invite a new player

1. The inviter opens **Invite a friend**, copies the personal `/r/<code>` URL and shares it.
2. The friend opens the link. An encrypted invitation cookie is saved for the configured lifetime. The friend can cancel before registering. If another valid link is opened before registration, the latest invitation replaces the previous one.
3. The friend **creates a new KaevCMS account**, using password registration or an enabled social provider. The invitation is accepted once. Logging into an existing account does not retroactively attach a referrer.
4. The invitee creates game accounts through the CMS and starts playing. Progress is tracked separately for each participating GameServer.

The player account displays invitation counts, milestones and personal reward history without exposing other users' private details. The inviter **does not need a character** on a GameServer to receive a Web Inventory item, but will need an eligible character to deliver that item into the game.

## When rewards are earned

- The CMS reads character levels using the existing GameServer gateway, never a browser-supplied value. **Any single character** belonging to the invitee can satisfy a level threshold; levels are not added together.
- A server-specific reward snapshot is created upon the **first verified character participation** on that GameServer, provided it is enabled and has rules. If the character is already high-level, all configured lower or equal outstanding milestones may be earned immediately.
- The snapshot is **immutable**. Later edits to level, item ID or quantity do not rewrite an existing participant's promises for that GameServer. A different server gets a separate snapshot.
- Each entitlement has a stable idempotency key. Level scanning marks the reward as earned; a separate scheduled step delivers the item to Web Inventory without double-crediting on retries.
- Deleting or replacing a character after a successful scan does not reset already earned milestones. If a character reaches a threshold and is deleted **before any successful scan**, the CMS cannot reconstruct that transient event without game-side events.

## Scheduler and troubleshooting

The module uses Laravel's existing Scheduler. Configure the normal Cron job to execute `php artisan schedule:run` **every minute**. No extra daemon or Composer dependency is needed. The module commands are scheduled every five minutes:

```text
php artisan kaevcms:referrals-scan --batch=30
php artisan kaevcms:referrals-grants --batch=30
```

The scanner discovers character participation and updates levels. The grant worker delivers already earned entitlements. Per-invitation polling can back off; a connection failure is not treated as level zero and does not erase history. Players may use **Check progress**, with a five-minute cooldown per invitation/server pair.

If an item does not appear, check module and GameServer enablement, reward-side switches, active rules, a qualifying character, gateway availability, Cron, invitation freeze status and email verification when required by the CMS. Administration provides separate progress and grant history. **An item in Web Inventory does not prove delivery to the game's database.**

## Pauses and limits

- Disabling the module/server or pausing checks suspends relevant new processing without deleting history. An administrator can manually **freeze/unfreeze** an invitation with a recorded reason and audit entry.
- An optional limit restricts the number of **rewarded invitees per inviter and GameServer**. It is unlimited by default. Invitations that already received rewards retain their remaining recorded promises.
- The module does not mint CMS wallet coins, grant directly to characters, support referral chains or award a percentage of donations. Referral links alone do not prevent all multi-account abuse.
- The existing GameServer dependency check blocks removal when it would erase referral history.
