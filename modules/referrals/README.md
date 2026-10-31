# Referrals

A separate KaevCMS module for invitation-based GameServer milestone rewards.

## Current development stage

Iteration 2 adds stable, opaque links at `/r/{code}`, a Laravel-encrypted,
HttpOnly, SameSite=Lax invitation cookie (default 7 days), and one-time binding
when the invited person creates a **new** CMS account using password or an
enabled social provider. Existing users never acquire a referral by signing in.
The invitation can be declined before registration.

The module remains disabled by default. Module migrations are applied by the
administrator from **Admin → Modules**. The global invitation switch remains
disabled until the administrator enables it. Per-server reward settings, level
checks and delivery are not available yet. There are no wallet or inventory
operations in this stage.

Invitations are linked to new CMS users, not game accounts. Enabling this early
foundation does not award items or read live GameServer databases.
