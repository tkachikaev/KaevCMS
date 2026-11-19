# Forum

The `forum` module adds a separate discussion forum to KaevCMS at `/forum`.

## Enable and access

An Owner enables the module under **Administration → Modules**. The enabled module appears in player account navigation and opens at `/forum`.

Forum ownership is determined by the active CMS administrator session. Players use their existing accounts and public nicknames. No separate registration or forum passwords are needed.

When an Owner is logged in as both a CMS administrator and a player, the forum offers a way to choose the active author without logging out.

## Available functionality

Version 0.1.0 exposes the landing page; categories and posts are not yet available. Only the Owner sees the settings link. The module does not grant permissions elsewhere in the CMS.

Disabling the module closes the public page and administrative route without deleting stored data. The forum does not need a game database connection.
