# Content, themes, and localization

KaevCMS ships separate public and player-account theme systems. Activating an account theme does not change the public theme.

Content areas include multilingual news, pages, navigation, site identity, logos, favicons, and theme-specific settings. HTML is sanitized both when saved and when rendered. Uploaded images are restricted to supported raster formats and stored under public uploads. News and pages support localized slugs, drafts, and previews; news can have a cover image and inline images, and pages can be placed in the navigation.

Themes are validated packages with a manifest, views, translations, and public assets.

Built-in Russian and English catalogs must have matching keys and placeholders. Additional reviewed language packs are discovered without a database migration. Public canonical links and localized slugs are generated per enabled language.

The bundled Kaev Aurelia Account theme uses a persistent Livewire shell, rounded dashboard surfaces, separate pages for characters and game accounts, a web inventory, and a modal account-avatar picker.

## Player account

The overview is compact. A first-game-account panel is shown only while the player has no game accounts; afterwards the page opens directly with account metrics and quick actions.

Account settings are not part of the game navigation. On desktop and mobile one account menu opens from the top-right avatar. It contains the wallet balance (a link to **My wallet**), **Account settings**, **Security and password**, a link back to the website, and sign out. Modules such as Donations add their own sections to the left game navigation.

- **Account settings** contains the KaevCMS profile avatar and read-only username and email.
- **Security and password** changes the KaevCMS password. It requires the current password, uses the same rules as registration, and states that game-account passwords are not changed. A successful change rotates the remember token, renews the current session, writes an audit record, and sends the password-change email when mail delivery is configured.

Profile avatars are discovered from `public/uploads/account-avatars` and are never reused as game-account or character icons.

When the language is changed on a module page, KaevCMS returns the player to the localized account overview, because module addresses have no language prefix.

## Account theme components

Bundled account themes show the page's `title` section as the single `h1` in the shared top bar. Account pages and module views define `@section('title', ...)` and may add a `description` section for the subtitle below it. The content keeps actions and data without repeating the heading or description; remove an empty heading wrapper when no actions remain. The top bar is re-rendered during Livewire navigation. On phones the title takes its own row below the menu and profile buttons and long titles wrap. On desktop the menu is 260px wide and the content area grows up to 1480px. The wallet's **Top up balance** link appears only when Donations registers its visible player entry.

Use these shared components:

- `<x-account-page>` for the content stack;
- `<x-account-panel>` for main panels (`flush` for lists with their own row padding, `layout="row"` for balance summaries);
- `<x-account-panel-header>` with `summary` and `actions` slots;
- `<x-account-stat>` for compact summary cards and `<x-account-empty>` for an empty state.

Actions belong inside the related panel. Main sections have no separate "Back to overview" row; nested pages keep a link to their parent inside the panel. Overview metrics keep their compact tiles. Spacing is controlled by `--account-panel-gap`, `--account-panel-padding` and `--account-panel-radius`: 24px on desktop, 18px spacing and padding with a 20px radius on phones. Module pages use the same components and keep their own fields, filters and permissions.

Use `.account-tabs` both for navigation links and for Livewire tab buttons; mark the active link with `aria-current` and the active button with `aria-selected`. The player sidebar footer contains only the return-to-site link. Theme names and build versions are shown only in administration.

## Pages behind theme buttons

Theme buttons open an ordinary page by its address key, the page address in the default language: "About the server" opens the page with the address `about`, "Download client" the page with the address `downloads`. Themes call `page_key_url('about')`; `download_page_url()` is the same for `downloads`. Create a page with that address and the button picks it up; a theme can add a button for any other key the same way. Without a published page with the key, the button stays and its address answers "not found".

A new installation creates two example pages, "About the server" (`about`) and "Download the game" (`downloads`). They are ordinary pages: edit, unpublish or delete them freely; upgrades never recreate a deleted page. The old `/about` and `/downloads` addresses redirect to the pages with these keys. A translation with an empty address takes the address typed in another language, so a page keeps one key in every language.

The download page content, including card headings, descriptions and buttons, lives in the visual editor. Click button text and choose **Link** to set its address and appearance; **Cards**, **Add card** and **Delete card** manage the layout without HTML, and a button without an address stays inactive.

