# Game assets / Игровые изображения

KaevCMS stores code/catalogs and image packs separately. Full, patch, cumulative, and shared-hosting release archives do not contain image binaries under `public/game-assets` and do not delete owner-provided files from this directory.

## Item icons

The current catalog profiles are `interlude`, `classic`, `high-five`, and `shine-maker`.

The recommended shared pool is:

```text
public/game-assets/items/{icon_key}.webp
```

Example: `item_id = 4` resolves through the selected profile catalog to `weapon_club_i00.webp`.

For compatibility with KaevCMS 0.33.6, profile-specific folders are also checked after the shared pool:

```text
public/game-assets/items/{profile}/{icon_key}.webp
```

Therefore an existing Interlude pack under `public/game-assets/items/interlude` continues to work. A shared filename is stored once even when several chronicles reference the same icon key.

## Catalog coverage

| Profile | Item IDs | Icon keys | Available WebP | Missing WebP | Missing icon key |
|---|---:|---:|---:|---:|---:|
| Interlude | 9,208 | 1,974 | 1,879 | 95 | 27 |
| Classic | 19,791 | 3,097 | 2,683 | 414 | 68 |
| High Five | 19,198 | 3,498 | 3,049 | 449 | 61 |
| Shine Maker | 19,790 | 3,097 | 2,683 | 414 | 68 |

The deduplicated external image pack contains 4,167 physical WebP files. Missing images use the normal no-icon fallback; catalog item names remain available. Unsupported negative item IDs are intentionally excluded.

## Character avatars

External character packs use:

```text
public/game-assets/characters/{profile}/{race}/{gender}/{archetype}.webp
```

Empty folder hierarchies are included for `interlude`, `classic`, `high-five`, and `shine-maker`. See [CHARACTER_AVATARS.md](CHARACTER_AVATARS.md).

## Owner overrides

Numeric per-server/common item overrides and character overrides remain under `public/uploads/game-assets`:

```text
public/uploads/game-assets/items/servers/{server_id}/{item_id}.webp
public/uploads/game-assets/items/common/{item_id}.webp
public/uploads/game-assets/characters/servers/{server_id}/...
public/uploads/game-assets/characters/common/...
```

Server-specific uploads have the highest priority, then common uploads, then the external standard pack.

---

KaevCMS хранит код/каталоги и пакеты изображений отдельно. Архивы full, patch, cumulative и shared-hosting не содержат бинарные изображения из `public/game-assets` и не удаляют файлы владельца из этой папки.

Рекомендуемый общий пул иконок:

```text
public/game-assets/items/{icon_key}.webp
```

Для совместимости также поддерживается старый путь:

```text
public/game-assets/items/{profile}/{icon_key}.webp
```

Поэтому существующая папка `public/game-assets/items/interlude` продолжает работать. Аватары персонажей размещаются в `public/game-assets/characters/{profile}/{race}/{gender}/{archetype}.webp`. Переопределения из `public/uploads/game-assets` сохраняют приоритет.

Каталоги содержат 9 208 предметов Interlude, 19 791 Classic, 19 198 High Five и 19 790 Shine Maker. Общий внешний пакет содержит 4 167 уникальных WebP. Для части ключей исходные изображения отсутствуют; в этом случае название предмета работает, а интерфейс использует обычный fallback без иконки. Некорректные отрицательные ID предметов намеренно исключаются.
