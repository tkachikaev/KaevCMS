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

Version **0.4.0 (F5)** uses the same **Tiptap** engine as News and Pages, with a compact forum-specific toolbar: bold, italic, underline, lists, quotations, code and links. Images, uploads, tables and decorative formatting are not offered on the forum.

Submitted HTML is sanitized on the server using a separate restricted forum profile; scripts, iframes, unsafe links, and unsupported attributes are removed. Legacy F4 posts remain escaped plain text: no automatic content conversion or data loss. A plain textarea remains available when JavaScript is unavailable.

Re-submitting a form with the same submission key does not create duplicate posts.

If the CMS requires verified email addresses, unverified players cannot post. The forum already enforces the optional account-age restriction for URLs in topic titles and messages. The default value is **0 days**, so links are allowed immediately.

## Global settings and moderators

The Owner manages the forum under **Administration → Modules → Forum → Settings**:

- **Forum visibility:** everyone (including guests) or registered users only. This restricts section lists and direct topic links; per-section permissions still apply.
- **Days before links are allowed:** an integer from 0 to 3650. A value of 0 permits links immediately. The restriction applies to ordinary players, not the Owner or forum moderators.
- **Moderators:** search for an existing user by public name, email address or exact user ID, then choose **Assign**. Search results are limited to 20 active users; the user database is not listed without a query. Use the current moderators list to remove a moderator. Inactive accounts cannot be assigned; previously assigned inactive accounts can still be removed.

Only the **Owner** can change these settings or assignments. Moderators gain forum permissions, not CMS administration access. Permission changes take effect on subsequent requests. Changes are recorded in the action journal. Enabling or disabling the module remains in **Modules**, with no second switch.

## Editing, deletion and revision retention

Version **0.6.0** lets authors edit their own posts without a time limit using **Edit** next to each message. A small **Edited** label appears afterward. Ordinary users cannot modify posts in closed, hidden or deleted topics.

**Delete** replaces the visible message with a tombstone. The post stays in place, preserving links, order and other users' replies, including when the opening message is removed. The author cannot restore removed text through the standard interface. The Owner can manage any post, while moderators may manage ordinary players' posts, not CMS staff or other moderators' posts. Outdated forms cannot overwrite newer content.

**Private history.** Each edit or deletion stores the previous text for 30 days. Only the Owner and moderators can open **Revision history**. Regular players cannot access older content even by a direct URL. Audit logs contain the action, not the old text.

**Cleanup.** The CMS core scheduler runs `kaevcms:forum-revisions-clean --batch=1000` hourly, including when the forum is disabled. Expired revisions stop appearing immediately and are deleted by the scheduled command. Laravel's scheduler must run regularly (normally `php artisan schedule:run` every minute) for timely retention cleanup. The command can also be run manually; it is safe without the forum tables and never removes current published text.

When upgrading from **0.5.0** to **0.6.0**, apply the new forum migration under **Administration → Modules**. Existing migrations and posts are preserved.

## Topic moderation and search

Version **0.7.0** lets the Owner and assigned forum moderators manage discussions directly from the topic page under **Manage topic**:

- **Close/reopen:** closed topics remain readable, but ordinary replies are blocked.
- **Pin/unpin:** pinned topics appear first in section listings.
- **Move:** choose an existing section. The regular **Archive** section is read-only, not a special topic state. All posts retain their IDs.
- **Hide/show:** a hidden topic is removed from ordinary section lists, search and counts. Authorized moderators can preview it via its direct URL and show it again.
- **Delete:** soft-deletes a topic while preserving all its messages. Use **Hidden and deleted topics** in the section to restore deleted discussions or unhide topics. Restoring a topic that was also hidden does not automatically publish it.

Rights use the current authenticated identity. The **Owner** can manage all topics. Forum moderators can manage ordinary player topics and their own topics, not topics authored by CMS administrators or other moderators. They cannot indirectly suppress protected staff replies by hiding or deleting a whole discussion. Every action is written to the standard audit log without storing post text.

**Moving a private discussion into a public section** is not permitted for moderators. The Owner must explicitly acknowledge the change of readership. Moving to a more restrictive section may make a topic inaccessible to ordinary readers; moderators cannot hide protected staff replies by such a move.

**Search** is available in the forum header. It searches current topic titles and post text using 2–80 characters. Results respect forum-wide and per-section visibility, exclude hidden/deleted topics, removed messages and historical revisions, and are paginated with request rate limits.

F8 adds no migrations or dependencies. Updating forum 0.6.0 to 0.7.0 only requires the usual module file update; any outstanding F7 migration still needs to be applied.

## Current limitations

Version 0.7.0 includes topic moderation, moving, search and recovery. Concurrency testing, additional security regressions and counter consistency on MySQL/MariaDB remain for the next stage.
