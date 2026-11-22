# Content, themes, and localization

KaevCMS ships separate public and player-account theme systems. Activating an account theme does not change the public theme.

New installations using the bundled `.env.example` start with **Aurelia** for the public site and **Classic** for the player account. This applies to the web installer, Windows setup and manually copying the environment template. Updates preserve existing `.env` files and saved theme selections.

Content areas include multilingual news, pages, navigation, site identity, logos, favicons, and theme-specific settings. HTML is sanitized both when saved and when rendered. Uploaded images are restricted to supported raster formats and stored under public uploads. News and pages support localized slugs, drafts, and previews; news can have a cover image and inline images, and pages can be placed in the navigation.

Themes are validated packages with a manifest, views, translations, and public assets.

Built-in Russian and English catalogs must have matching keys and placeholders. Additional reviewed language packs are discovered without a database migration. Public canonical links and localized slugs are generated per enabled language.

The bundled Aurelia theme uses a persistent Livewire shell, rounded dashboard surfaces, separate pages for characters and game accounts, a web inventory, and a modal account-avatar picker.

The bundled Classic theme is built for readability: a warm light palette and a moonlit dark palette with lavender accents, large high-contrast text (16 px body), single-line navigation with icons, 6 px corners and one thin border per block. A top-bar button switches between light and dark; the choice is kept in the player's browser (`localStorage`) and applied before the page paints, including after Livewire navigation. At the bottom of the menu the shared `<x-account-server-status />` component shows each game server's status, and its online count only when the site setting for public online is enabled. The theme styles only through `--account-*` variables, so both modes also reach module pages.

The Classic overview banner follows the chosen scheme: the light theme uses the daytime elf with gold particles, while the dark theme uses the moonlit elf with lavender particles. A small sparkle button in the top-right corner switches the effect on or off; the preference stays in the player's browser. Reduced motion disables the effect, while leaving the viewport, hiding the tab or navigating away stops rendering. Particles stay inside the artwork and do not intercept clicks.

Classic uses a compact sun/moon switch. The theme changes with a quick smooth crossfade: one elf fades out while the other fades in. Reduced motion or missing View Transitions support makes switching immediate. Navigating between sections finishes the effect and keeps the chosen scheme.

The public Aurelia 1.1.0 theme gained a night scheme: a sun/moon switch in the header (on phones, an entry at the end of the menu) toggles the light theme and a dark one with lavender accents and a night elf. The palette lives in `assets/css/dark.css` and applies only while `<html>` carries `data-site-scheme="dark"`; `assets/js/scheme.js` loads in `<head>` before the styles, so the saved choice (`localStorage`) applies before the first paint and survives Livewire navigation and language changes. The theme changes with a quick smooth crossfade: one elf fades out while the other fades in, in both directions; with reduced motion or without View Transitions it switches at once. The hero particles change palette with the theme. The language list opens smoothly: the panel lifts in and its rows follow one by one. After a page reload the portrait and copy no longer snap in: the artwork is fetched and decoded first, then fades in and glides slightly into place while the blocks rise in order (full page loads only, not Livewire visits or reduced motion).

## Forum themes

When the Forum module is enabled, **Admin → Themes** gets a **Forum** item next to Site and Account. Disable the module and the item goes away. The owner and administrators switch themes, auditors may only look; every switch is written to the audit log.

Two themes are bundled:

- **Frost**: cool blue-grey with light and dark modes. The easiest base for your own.
- **Classic**: warm, in the style of the Classic player account: paper and gold by day, a soft violet night in the dark mode.

A forum theme is a `public/forum-themes/<slug>` folder with three files:

- `theme.json`: `name`, `slug` (same as the folder name), `version`, `author`, optional `description`, `preview`, `cms_min`, `cms_max` and `forum_min` (the minimum Forum module version);
- `theme.css`: all forum styles. Dark mode rules start with `:root[data-forum-scheme="dark"]`. The `[data-color="…"]` rules paint the colors players pick in the editor; keep them as they are;
- a preview image for the admin card (PNG, WebP or SVG).

To make your own theme, copy the Frost or Classic folder, rename it, change `slug` and `name` in `theme.json` and edit `theme.css`. Browsers get a saved file at once: the stylesheet address changes with the file's modification time.

A theme holds no templates or PHP, only styles, so a theme from someone else cannot run code on the server. In addition, `theme.css` may load images and fonts only from its own folder (or `data:`). A theme with `@import`, `http(s):` addresses, `//` or `\` escapes is marked as damaged and cannot be activated: such links would let styles send page data to another site. Files over 1 MB are refused too. If the chosen theme is damaged or removed, the forum falls back to Frost.

New forum elements in future versions get styles in the bundled themes. For your own theme, compare its `theme.css` with the updated Frost and copy the new rules.

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

The site, account and administration Content Security Policy forbids inline scripts and handlers such as `onchange` and `onclick`: the browser silently blocks them. Attach behavior only through script files. To make a filter list submit its form immediately, add the `data-account-auto-submit` attribute to the `<select>` (the shared account runtime handles it) and keep a submit button inside `<noscript>` in the form.

## Pages behind theme buttons

Theme buttons open an ordinary page by its address key, the page address in the default language: "About the server" opens the page with the address `about`, "Download client" the page with the address `downloads`. Themes call `page_key_url('about')`; `download_page_url()` is the same for `downloads`. Create a page with that address and the button picks it up; a theme can add a button for any other key the same way. Without a published page with the key, the button stays and its address answers "not found".

A new installation creates two example pages, "About the server" (`about`) and "Download the game" (`downloads`). They are ordinary pages: edit, unpublish or delete them freely; upgrades never recreate a deleted page. The old `/about` and `/downloads` addresses redirect to the pages with these keys. A translation with an empty address takes the address typed in another language, so a page keeps one key in every language.

The download page content, including card headings, descriptions and buttons, lives in the visual editor. Click button text and choose **Link** to set its address and appearance; **Cards**, **Add card** and **Delete card** manage the layout without HTML, and a button without an address stays inactive.

