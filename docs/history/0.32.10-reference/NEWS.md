# News management

KaevCMS includes formatted news, preview before saving, deletion from the news list, pagination, cover images and inline images.

## Administrator routes

- `GET /admin/news` — list all news items;
- `GET /admin/news/create` — create form;
- `POST /admin/news/preview` — render unsaved form data through the active theme;
- `POST /admin/news` — save a new item;
- `POST /admin/news/images` — upload an image into the visual editor;
- `GET /admin/news/{news}/edit` — edit form;
- `PUT /admin/news/{news}` — save changes;
- `DELETE /admin/news/{news}` — permanently delete an item after administrator confirmation.

All routes require an authenticated administrator and Laravel CSRF protection. Inline image uploads are additionally rate-limited.

## Pagination

The administrator list displays 10 news items per page, ordered from newest to oldest. The public list remains paginated separately by the active theme and public controller.

## Publication states

- **Draft** — `is_published` is disabled; the item is invisible publicly.
- **Scheduled** — publication is enabled and `published_at` is in the future.
- **Published** — publication is enabled and `published_at` is current or past.

The public site only returns published items.

## Visual editor

The editor supports:

- headings;
- bold, italic, underline and strike-through;
- ordered and unordered lists;
- quotes and code blocks;
- safe links;
- left, center and right alignment;
- a limited text-color palette;
- horizontal separators;
- uploaded images inside the article.

The browser editor is only an interface. The server always parses and sanitizes the submitted HTML again before saving it.

## Images

Cover images are stored under:

```text
public/uploads/news/covers/YYYY/MM/
```

Images inserted into article content are stored under:

```text
public/uploads/news/content/YYYY/MM/
```

Only JPEG, PNG and WebP are accepted. Maximum file size is 5 MB and maximum dimensions are 6000×6000 pixels. Original filenames are not used.

The database stores relative paths, not image binary data.

## Preview before saving

The editor can submit the current form to `/admin/news/preview` in a new tab. The preview is rendered through the active public theme, is available only to an authenticated administrator, is never written to the database and is returned with `noindex`, `nofollow` and `no-store` headers. A newly selected cover is embedded as a temporary data URL and is not saved to disk.

## Deletion and media cleanup

The red delete action is located next to the edit action in the administrator news list. After confirmation, deleting a news item permanently removes its database row. Its cover and inline images are deleted only after the CMS verifies that no other active or soft-deleted news item references the same path. Removing an inline image while editing uses the same reference check.

## Cleaning unused images

Cover and inline images uploaded before an abandoned edit can remain on disk. Files older than 24 hours and not referenced by any active or soft-deleted news item can be inspected and removed with:

```powershell
php artisan kaevcms:news-media-clean --dry-run
php artisan kaevcms:news-media-clean
```

Use `--hours=48` to keep recent unreferenced files for a longer period.

## HTML allow list

The sanitizer permits only the elements needed by the editor, including paragraphs, headings, text emphasis, lists, quotes, links, figures and images. Scripts, styles, iframes, forms, SVG and event attributes are removed.

## Slugs

A unique URL slug is generated when the item is created. It remains unchanged when the title is edited, preventing existing links from breaking.

## Переводы новостей

В 0.10.0 редактор показывает отдельную вкладку для каждого включённого языка. Язык сайта по умолчанию требует заголовок и текст; дополнительный перевод можно оставить полностью пустым.

Обложка, статус и дата публикации общие. Текстовые поля и slug хранятся в `news_translations` с уникальной парой языка и slug. Публичные ссылки имеют вид:

```text
/ru/news/russkaya-novost
/en/news/english-news
```

При отсутствии перевода CMS перенаправляет посетителя на URL фактической резервной локали. Если в адресе текущего языка передан slug другого перевода, выполняется постоянный редирект на правильный slug. Публичная страница публикует `canonical`, `hreflang` и `x-default` только для существующих переводов.

Тема должна использовать `titleFor()`, `excerptFor()`, `safeBodyHtml()` и `news_url()`, а не читать старые поля модели напрямую.
