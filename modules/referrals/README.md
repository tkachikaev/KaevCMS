# Referrals

An independent KaevCMS module that links invitations to new CMS accounts and
configures per-GameServer character-level milestone rewards.

## Development stage: iteration 3

- Stable, opaque referral URLs `/r/{code}` and encrypted 7-day invitation cookie.
- Invites attach only to newly registered CMS users (password or social auth).
- Admin controls: global switch and invitation TTL, independent GameServer and
  audience switches, up to 30 unique levels per audience and server, item IDs
  and quantities, optional per-server rewarded-invitee limit, and audited manual
  freezing/unfreezing of invitations.
- Rules use the native item catalog where available; unknown custom item IDs
  remain configurable with a warning.
- Existing immutable snapshots, entitlements and progress remain untouched by
  rule changes or deletion. **Snapshots are not created by admin forms.** They
  will be captured at first verified GameServer participation in iteration 4.
- No character polling or rewards are performed at this stage. The optional
  rewarded-invitee limit is stored but not yet enforced.

Module migrations are applied manually from **Admin → Modules**. The module
and its global invitations switch are disabled by default. No new migration,
Composer dependency, CMS version change or payment operation is needed in
iteration 3.
