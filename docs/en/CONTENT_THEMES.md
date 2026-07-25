# Content, themes, and localization

KaevCMS ships separate public and player-account theme systems. Activating an account theme does not change the public theme.

Content areas include multilingual news, pages, navigation, site identity, logos, favicons, and theme-specific settings. HTML is sanitized both when saved and when rendered. Uploaded images are restricted to supported raster formats and stored under public uploads.

Built-in Russian and English catalogs must have matching keys and placeholders. Additional reviewed language packs are discovered without a database migration. Public canonical links and localized slugs are generated per enabled language.

The bundled Kaev Aurelia Account theme uses a persistent Livewire shell, rounded dashboard surfaces, separate pages for characters and game accounts, a web inventory, and a modal account-avatar picker.
