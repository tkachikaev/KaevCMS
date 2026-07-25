# Shared game assets

KaevCMS stores reusable game images in one persistent, web-accessible directory:

```text
public/uploads/game-assets/
```

Release patches do not replace or delete this directory. Include it in normal server backups.

## Directory layout

```text
public/uploads/game-assets/
├─ items/
│  ├─ common/
│  │  ├─ 57.webp
│  │  └─ 4037.png
│  └─ servers/
│     └─ 3/
│        └─ 57.png
└─ characters/
   ├─ common/
   │  ├─ human/
   │  │  ├─ male/
   │  │  │  ├─ warrior.webp
   │  │  │  ├─ mage.webp
   │  │  │  └─ default.webp
   │  │  └─ female/
   │  │     ├─ warrior.webp
   │  │     ├─ mage.webp
   │  │     └─ default.webp
   │  └─ fallback/
   │     └─ neutral/
   │        └─ default.webp
   └─ servers/
      └─ 3/
         └─ human/
            └─ female/
               └─ mage.png
```

Supported extensions are checked in this order:

```text
webp, png, jpg, jpeg
```

## Localized item names

Item names are resolved separately from images through `lang/{locale}/items.php`. The catalog is shared by promo codes, web inventory and reward journals. See `docs/GAME_ITEMS.md` for the common and per-GameServer formats.

## Item icon resolution

Core pages and modules use `App\Services\GameAssets\GameAssetUrlResolver` instead of constructing paths themselves.

For item `57` on GameServer `3`, the resolver checks the server-specific folder first and then the common folder. This allows a server or custom build to override a shared image.

```php
$url = $assets->itemIcon($gameServer, 57);
```

## Character avatar resolution

`App\Services\GameAssets\CharacterAppearanceResolver` converts game data into a deliberately small visual description:

```text
race + gender + warrior|mage|default
```

It does not require a separate image for every profession. For example, all Human female magical professions may use:

```text
characters/common/human/female/mage.webp
```

Races configured with one visual branch, such as Dwarf or Kamael, resolve to `default` regardless of profession:

```text
characters/common/dwarf/female/default.webp
characters/common/kamael/male/default.webp
```

Known race keys are:

```text
human, elf, dark_elf, orc, dwarf, kamael, ertheia, sylph
```

Gender keys are:

```text
male, female, neutral
```

Unknown races and classes are not guessed. They use safe `unknown` or `default` fallbacks.

### Fallback order

For `human/female/mage`, KaevCMS checks these keys in order inside the server-specific folder and then inside the common folder:

1. `human/female/mage`
2. `human/female/default`
3. `human/neutral/mage`
4. `human/neutral/default`
5. `fallback/female/mage`
6. `fallback/female/default`
7. `fallback/neutral/default`

A server-specific fallback has priority over a common exact image. This lets one server provide a complete custom avatar pack without mixing it with common assets.

If no file exists, the bundled themes keep their letter-based placeholder. KaevCMS does not ship copyrighted character artwork.

```php
$appearance = $appearances->resolve($gameServer, $raceId, $genderId, $classId);

$appearance['race_key'];   // human
$appearance['gender_key']; // female
$appearance['archetype'];  // mage
$appearance['avatar_url']; // URL or null
```

The resolver is used by the player character directory, account details, web-inventory character selection and public statistics.

## Operations

`setup.ps1` and `update.ps1` create the base asset directories but never delete their contents. System diagnostics checks that `public/uploads/game-assets` is writable.

Images may be copied in bulk. File names and path segments must contain only safe Latin letters, numbers, underscores, hyphens and the documented directory separators. Absolute paths and traversal sequences are rejected.
