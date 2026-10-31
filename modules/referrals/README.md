# Referrals

A separate KaevCMS module for invitations and GameServer-specific level rewards.

In the first development stage only the schema, model classes, read-only admin
landing page and access/deletion guards are available. The module is inactive by
default, and it does not create invitations, check character levels or grant items.

Install it using **Admin → Modules**, which also applies its module migrations.
Do not run these migrations against production without a backup. The default
`enabled` switches for the global and per-server settings are `false`.

Invitations, settings editing, polling and rewards will be implemented in later
stages. The module does not modify Wallet or Composer dependencies.
