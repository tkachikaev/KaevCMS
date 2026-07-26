# Game assets / Игровые изображения

KaevCMS uses one canonical owner-managed directory for all game images:

```text
public/uploads/game-assets
```

This directory is runtime data. Full, patch, cumulative, and shared-hosting release archives do not include its contents and updates do not overwrite it.

## Item icons

Put shared item icons into the explicit `common` directory:

```text
public/uploads/game-assets/items/common/{item_id}.webp
public/uploads/game-assets/items/common/{icon_key}.webp
```

Examples:

```text
public/uploads/game-assets/items/common/57.webp
public/uploads/game-assets/items/common/etc_adena_i00.webp
public/uploads/game-assets/items/common/accessary_angel_circlet_i00.webp
public/uploads/game-assets/items/common/armor_t45_g_i00.webp
```

KaevCMS first checks the numeric item ID and then the icon key from the selected item catalog. WebP is recommended; PNG, JPG, and JPEG are also supported.

A server-specific override has priority:

```text
public/uploads/game-assets/items/servers/{server_id}/{item_id}.webp
public/uploads/game-assets/items/servers/{server_id}/{icon_key}.webp
```

Item catalogs remain profile-specific (`interlude`, `classic`, `high-five`, `shine-maker`), but the physical icon pool is shared. The old `public/game-assets` and profile image folders are not used. Shared files live explicitly under `common`, while `servers/{server_id}` contains overrides.

## Character avatars

Character artwork is independent from the chronicle and uses:

```text
public/uploads/game-assets/characters/common/{race}/{gender}/{archetype}.webp
```

Example:

```text
public/uploads/game-assets/characters/common/human/female/mage.webp
```

A server-specific override has priority:

```text
public/uploads/game-assets/characters/servers/{server_id}/{race}/{gender}/{archetype}.webp
```

See [CHARACTER_AVATARS.md](CHARACTER_AVATARS.md).

---

KaevCMS использует один канонический каталог пользовательских игровых изображений:

```text
public/uploads/game-assets
```

Это runtime-данные владельца сайта. Содержимое каталога не входит в full, patch, cumulative и shared-hosting архивы и не перезаписывается обновлениями.

Общие иконки предметов размещаются в явной папке `common`:

```text
public/uploads/game-assets/items/common/{item_id}.webp
public/uploads/game-assets/items/common/{icon_key}.webp
```

Сначала CMS ищет файл по числовому ID, затем по ключу иконки из каталога выбранной хроники. Для отдельного сервера используется:

```text
public/uploads/game-assets/items/servers/{server_id}/{item_id}.webp
public/uploads/game-assets/items/servers/{server_id}/{icon_key}.webp
```

Аватары персонажей не зависят от хроники и размещаются в `characters/common`:

```text
public/uploads/game-assets/characters/common/{race}/{gender}/{archetype}.webp
public/uploads/game-assets/characters/servers/{server_id}/{race}/{gender}/{archetype}.webp
```

Старый каталог `public/game-assets` и разбиение изображений по хроникам больше не используются. Общие файлы находятся в `common`, а серверные переопределения — в `servers/{server_id}`.
