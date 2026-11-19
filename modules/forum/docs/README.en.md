# Forum

The `forum` module adds a separate discussion forum at `/forum`.

## Installation

The CMS Owner enables the module under **Administration → Modules**. The forum can then be opened at `/forum` or from the player account navigation.

The CMS Owner manages the forum using their existing administrator session. Players use existing accounts and public display names. There are no extra passwords or game-database dependencies. When both web and administrator sessions exist, the author can be selected explicitly.

## First setup

The basic structure is created automatically when the module is enabled for the first time:

- **Information** → News and announcements (Owner creates topics; replies disabled).
- **Community** → General discussion, Questions and help, Suggestions and ideas (registered users may post).
- **Other** → Archive (read-only for everyone).

Existing categories, including those in Trash, are never overwritten or recreated during an upgrade. The Owner may edit, remove, or add categories and sections afterward. Disabling and enabling the forum does not recreate deleted categories.

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

## Topics and replies

In a section that allows posting, click **New topic**, enter a title and a message, and publish it. Signed-in players may reply according to the section's permissions. Guests can read public discussions, but cannot post.

Sections list topics with authors, reply counts, and last activity. Topics are sorted by latest activity, 20 per page. Each topic shows up to 15 chronological messages per page.

CMS Owner and Administrator accounts post under their existing administrative names with an Administrator label. Appointed forum moderators post under their public player nicknames with a Moderator label. When two login identities exist in the same browser, the author can be selected explicitly.

Publishing checks current section permissions and topic state on the server. By default only Owner can start topics in News and announcements and replies are disabled; Archive is read-only. Removing a section hides its topics without erasing messages.

For now, messages use **plain text only**, safely rendered without executing user-supplied HTML. Re-submitting a form with the same submission key does not create duplicate posts.

If the CMS requires verified email addresses, unverified players cannot post. The forum already enforces the optional account-age restriction for URLs in topic titles and messages. The default value is **0 days**, so links are allowed immediately.

## Current limitations

Version 0.3.0 adds topics and replies. Editing, deleting, moving/locking/pinning topics, the 30-day revision history, and the rich-text editor will arrive later. Global visibility, link age limits and moderator assignment are not yet editable through the settings UI.
