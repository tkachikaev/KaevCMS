# Forum

The `forum` module adds a separate discussion forum at `/forum`.

## Installation

The CMS Owner enables the module under **Administration → Modules**. The forum can then be opened at `/forum` or from the player account navigation.

The CMS Owner manages the forum using their existing administrator session. Players use existing accounts and public display names. There are no extra passwords or game-database dependencies. When both web and administrator sessions exist, the author can be selected explicitly.

## First setup

The forum is empty on installation. The Owner can click **Create basic structure**:

- **Information** → News and announcements (Owner creates topics; replies disabled).
- **Community** → General discussion, Questions and help, Suggestions and ideas (registered users may post).
- **Other** → Archive (read-only for everyone).

This is a one-time action and never replaces existing categories. The Owner may build everything manually instead.

## Managing categories and sections

The Owner creates, edits, orders, removes and restores categories and sections directly on `/forum`. Every item has Russian and optional English titles and descriptions; the Russian label is used when English is empty. A section can be moved to a different category.

Each section has three permission settings:
- **Read:** everyone / registered users / moderators and Owner.
- **Create topics:** registered users / moderators and Owner / Owner only / nobody.
- **Reply:** the same set of choices.

Posting cannot be available to a broader audience than reading. Guests cannot post. Administrator accounts without the Owner role do not automatically gain forum management or moderator rights.

Categories with active sections cannot be removed. Removed categories and sections are recoverable from **Trash**. Disabling the forum does not drop its tables.

## Archive

Archive is an ordinary read-only section. Once the topic-moving functionality is available, moderators can move old topics there instead of deleting them.

## Current limitations

Version 0.2.0 provides categories, sections and their reading permissions. Posting, moderation and topic moves will arrive in later versions. Global visibility, link age limits and moderator assignments are not yet editable in the UI.
