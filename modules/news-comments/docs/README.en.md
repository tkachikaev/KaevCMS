# News Comments module

The `news-comments` module adds discussions beneath published news articles. Data is stored in the primary KaevCMS database, independent of game servers.

**Current development branch:** installation, settings and player submission, reply, edit and delete interfaces are implemented. **The administrator moderation queue is not available yet.** In manual-review mode, new submissions will remain pending without any staff action screen. Do not deploy this branch as a complete production comments feature.

## Enable and configure

1. Open **Modules** in the administration area and enable **News Comments**. KaevCMS applies module migrations automatically.
2. Open **News Comments** in the administration navigation.
3. **Enable news comments** is off by default. Do not enable manual-review mode on a production site before the administration queue is implemented.

## Settings

- **Publication:** automatically or after moderation (default). Pending messages have no automatic expiration.
- **Allow users to edit their comments:** on by default. Automatic edits appear immediately; each comment can only be edited every five minutes. With manual moderation, the previously approved text and replies stay visible until the new version is approved; rejection preserves the old text.
- **Allow Editors to moderate:** off by default. Editors cannot delete full threads or change settings.
- **Limits:** new submissions per user during a rolling 24-hour period, across all articles and per article, minimum submission interval, and text length. Deleting comments does not restore submission quotas. Editing does not use the daily quota.

Each news article may **Inherit**, **Allow**, or **Deny** comments. The global disabled setting always takes precedence.

Only the **Owner** can modify settings and per-article policies. Administrators and auditors can view them. Enabling Editor moderation does not grant settings access.

## Player comments

Signed-in players may post beneath a published article, reply to a top-level comment (one level of replies), edit or delete their own text. Guests can read approved posts but must sign in to write. When email verification is required, unverified accounts cannot comment.

Automatic publication displays new posts immediately. Under manual review, only the author sees their pending comment. Editing an approved comment keeps its previous text and replies publicly visible until staff approves the replacement; the proposed revision is visible only to its author and staff. Further edits are blocked while a revision waits. Published edits receive an **Edited** label; there is no public revision history.

## Deleting comments

- An author can delete their own comment. With no replies, it is removed completely. When replies exist, its text is erased and a **Comment deleted** placeholder preserves other people's replies.
- An administrator may permanently remove a root comment and its complete reply thread.
- Permanently deleting an article deletes all associated comments, proposed edits, submission events and per-article settings. There is no trash or restoration.

## Custom themes

Both bundled themes, **default** and **kaev-aurelia**, contain the extension slot. In a third-party theme, insert the same line directly after the article body, as used in `themes/default/views/news/show.blade.php`:

```blade
@include('news.extensions', ['news' => $news])
```

Without this line, the news page works but does not display comments. When the module is disabled, the slot produces no output.
