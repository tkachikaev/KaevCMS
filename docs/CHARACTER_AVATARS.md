# Character avatar image pack / Пакет аватаров персонажей

KaevCMS does not include character artwork. Shared owner-provided images live under:

```text
public/uploads/game-assets/characters/common
```

Character avatars are shared by every chronicle. Use this hierarchy:

```text
characters/common/human/male/warrior.webp
characters/common/human/male/mage.webp
characters/common/human/female/warrior.webp
characters/common/human/female/mage.webp
characters/common/elf/male/warrior.webp
characters/common/elf/male/mage.webp
characters/common/elf/female/warrior.webp
characters/common/elf/female/mage.webp
characters/common/dark_elf/male/warrior.webp
characters/common/dark_elf/male/mage.webp
characters/common/dark_elf/female/warrior.webp
characters/common/dark_elf/female/mage.webp
characters/common/orc/male/warrior.webp
characters/common/orc/male/mage.webp
characters/common/orc/female/warrior.webp
characters/common/orc/female/mage.webp
characters/common/dwarf/male/default.webp
characters/common/dwarf/female/default.webp
characters/common/kamael/male/default.webp
characters/common/kamael/female/default.webp
characters/common/ertheia/male/warrior.webp
characters/common/ertheia/male/mage.webp
characters/common/ertheia/female/warrior.webp
characters/common/ertheia/female/mage.webp
characters/common/sylph/male/default.webp
characters/common/sylph/female/default.webp
characters/common/fallback/male/default.webp
characters/common/fallback/female/default.webp
characters/common/fallback/neutral/default.webp
```

Example full path:

```text
public/uploads/game-assets/characters/common/human/female/mage.webp
```

Server-specific overrides have priority and use:

```text
public/uploads/game-assets/characters/servers/{server_id}/human/female/mage.webp
```

Resolution order:

1. Server-specific image.
2. Shared image from `characters/common`.
3. Built-in text fallback.

WebP is recommended; PNG, JPG, and JPEG are also supported. Use square images with consistent dimensions.

---

KaevCMS не включает изображения персонажей. Общие изображения владельца сайта хранятся здесь:

```text
public/uploads/game-assets/characters/common
```

Аватары общие для всех хроник. Внутри `common` используется структура `раса/пол/архетип.webp`. Например:

```text
public/uploads/game-assets/characters/common/human/female/mage.webp
```

Индивидуальное изображение для конкретного игрового сервера имеет приоритет:

```text
public/uploads/game-assets/characters/servers/{server_id}/human/female/mage.webp
```

Папки `interlude`, `classic`, `high-five` и `shine-maker` для аватаров не используются. Изображения не входят в релизные архивы и не перезаписываются обновлениями.
