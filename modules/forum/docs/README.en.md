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

The forum home shows each section's topic and message totals and its latest message: the author's avatar, the topic, the time and the name. Sections list topics with the author's avatar and name, reply and view counts, and the latest reply with the replier's avatar. A view counts once per visitor session, so reloading does not inflate it. The view counter needs the module migration `2026_11_23_000500_add_view_count_to_module_forum_topics.php`: after updating, apply it under **Admin → Modules** ("Update database"); existing topics start counting from zero. Topics are sorted by latest activity, 20 per page. Each topic shows up to 15 chronological messages per page.

CMS Owner and Administrator accounts post under their existing administrative names with an Administrator label. Appointed forum moderators post under their public player nicknames with a Moderator label. When two login identities exist in the same browser, the author can be selected explicitly.

Publishing checks current section permissions and topic state on the server. By default only Owner can start topics in News and announcements and replies are disabled; Archive is read-only. Removing a section hides its topics without erasing messages.

Since version **0.4.0** the forum uses the same **Tiptap** engine as News and Pages, with a compact forum-specific toolbar: bold, italic, underline, text color (a palette of 40 tones, each tuned for the light and dark schemes), left/center/right alignment, lists, quotations, code, emoji and links. The link dialog accepts link text, so a message can show "click here" instead of the bare address. Arbitrary colors (HEX) and styles are not accepted: the server keeps palette colors only. Images are inserted **by link only** (the "Image by link" button): a direct https:// link to a picture on one of the allowed sites. The Owner sets the list under **Admin → Modules → Forum → Images in posts** (one site per line); by default it is **iili.io** (freeimage.host), **i.imgur.com**, **i.postimg.cc**, **i.ibb.co**. An empty list turns forum images off, and a removed site also stops showing in older posts. Add only image hosts where an uploaded file cannot be silently replaced at the same address. The list needs the module migration `2026_11_23_000600_add_image_hosts_to_module_forum_settings.php` (Admin → Modules → "Update database"). The field also accepts a viewer page link (freeimage.host, imgur.com) or the HTML/BB/Markdown code these sites offer; the editor finds the direct link itself. Nothing is uploaded to or processed by the CMS server: readers' browsers load the picture. The dialog shows a preview. In the editor an image can be aligned with the alignment buttons and scaled by dragging its corner: the proportions are kept and it never grows beyond its natural size. The server accepts https:// addresses on these sites only (exact host names, no subdomains, ports or credentials) and asks readers' browsers not to send the page address to the image host. The account-age rule for links also applies to images. The **Media** button inserts a video from an ordinary link: **YouTube** (videos, Shorts, live) and **Twitch** (videos, clips, channels). A post stores only the site and the video id, and the forum builds the player itself when showing it; YouTube uses its privacy-enhanced mode (youtube-nocookie.com). Arbitrary player code cannot be inserted. The security policy allows these two players on forum pages only. The account-age rule for links also applies to videos. File uploads, attachments and tables are not offered on the forum.

Submitted HTML is sanitized on the server using a separate restricted forum profile; scripts, iframes, unsafe links, and unsupported attributes are removed. Posts written before the editor arrived (versions before 0.4.0) remain escaped plain text: no automatic content conversion or data loss. A plain textarea remains available when JavaScript is unavailable.

Re-submitting a form with the same submission key does not create duplicate posts.

If the CMS requires verified email addresses, unverified players cannot post. The forum already enforces the optional account-age restriction for URLs in topic titles and messages. The default value is **0 days**, so links are allowed immediately.

## Activity table on the home page

Above the sections a table offers **Latest messages**, **New topics**, **Popular topics** (most messages in the last 7 days) and, for signed-in players, **My topics**. It only lists topics from sections the visitor can already see; hidden and deleted topics never appear. Shared lists refresh once a minute. Under **Admin → Modules → Forum → Activity table on the home page** the Owner switches the table on or off and sets topics per tab (1 to 30, default 10). It needs the module migrations `2026_11_23_000700_add_activity_limit_to_module_forum_settings.php` and `2026_11_23_000800_add_activity_enabled_to_module_forum_settings.php` (Admin → Modules → "Update database").

## Notifications (the bell)

Signed-in players see a bell in the forum header. A red number shows unread notifications, and the bell rings briefly when something new arrives. Players are notified when:

- someone replies in their topic (several replies in a row merge into one: "3 new replies");
- someone quotes their message (the Quote button);
- someone answers their message with the Reply button.

Nobody is notified about their own messages, and a notification about a topic in a restricted section only goes to players who can read that section. Clicking a notification opens the message and marks it read; "Mark all read" is available. The number refreshes by itself about every two minutes. Read notifications are removed after 60 days and any notification after 180 days (the CMS scheduler's daily cleanup). Notifications live in the shared CMS table `user_notifications` (core migration `2026_11_23_100000_create_user_notifications_table.php`), applied by the regular CMS update.

## Global settings and moderators

The Owner manages the forum under **Administration → Modules → Forum → Settings**:

- **Forum access:** open to everyone (guests read the sections that allow it) or registered users only. This restricts section lists and direct topic links; per-section permissions still apply.
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

Version 0.7.0 adds no migrations or dependencies. Updating forum 0.6.0 to 0.7.0 only requires the usual module file update; if the revision-history migration from 0.6.0 is still pending, apply it.

## Appearance and usability

Starting with version **0.8.0**, the forum follows the CMS public theme: the built-in Dark theme uses dark colors, while Kaev Aurelia uses the site's existing saved light/dark preference. There is no separate forum appearance setting. Navigation, search, forms and the editor adapt to narrower screens. Keyboard focus indicators and a **Skip to content** link improve navigation.

Deleting a post or an entire topic now requires **two deliberate actions**: opening the confirmation and submitting **Confirm deletion**. These are ordinary HTML forms, so confirmation works without JavaScript. The server continues to enforce authorization regardless of UI controls.

## Additional write safeguards

When creating topics, replying or editing text, the current link-posting policy is rechecked under a database row lock before committing the content. Moderator assignments are likewise revalidated under a write lock, so a revoked assignment cannot be used for an in-flight change based on stale permissions. Existing storage and counters remain in use; no separate search database is introduced.

Version 0.8.0 adds **no new database migrations**. If the revision-history migration from 0.6.0 (\`2026_11_20_000400_create_module_forum_post_revisions.php\`) has not yet been applied, apply it under **Administration → Modules**. Keep the CMS scheduler running to purge expired revisions.

## Current limitations

Version 0.8.0 includes the planned forum functionality. Before public deployment, run local PHPUnit, Pint, PHPStan, Playwright and genuine concurrent checks on an isolated MySQL/MariaDB instance. For larger forums, inspect query plans and latency for the text search. Do not run destructive checks against live game databases.
