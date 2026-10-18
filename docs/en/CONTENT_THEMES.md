# Content, themes, and localization

KaevCMS ships separate public and player-account theme systems. Activating an account theme does not change the public theme.

Content areas include multilingual news, pages, navigation, site identity, logos, favicons, and theme-specific settings. HTML is sanitized both when saved and when rendered. Uploaded images are restricted to supported raster formats and stored under public uploads.

Built-in Russian and English catalogs must have matching keys and placeholders. Additional reviewed language packs are discovered without a database migration. Public canonical links and localized slugs are generated per enabled language.

The bundled Kaev Aurelia Account theme uses a persistent Livewire shell, rounded dashboard surfaces, separate pages for characters and game accounts, a web inventory, and a modal account-avatar picker.

## Download page

Installation or the first upgrade creates an editable RU/EN download page with the reserved `downloads` address. Bundled theme download buttons resolve its localized address with `download_page_url()`. All page content, including card headings, descriptions and buttons, lives in the visual editor under **Pages**. Click button text and choose **Link** to set its address and appearance. **Cards**, **Add card**, and **Delete card** manage the layout without HTML. An empty button address stays inactive. Removing cards removes them from the website, with no extra template content appended. An additive upgrade moves the previous cards and saved URLs into each translation exactly once while preserving owner text.

Deleted pages are not recreated by subsequent upgrades. Create a normal page with the `downloads` address in the default language, or use the prefilled form offered in the page list. Missing or unpublished pages show a files-unavailable message. Upgrades preserve existing downloads content and URLs.
