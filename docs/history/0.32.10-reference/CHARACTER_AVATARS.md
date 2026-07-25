# Character avatar foundation

KaevCMS 0.31.3 provides the avatar-selection foundation but no character artwork. Add your own legally usable images under:

```text
public/uploads/game-assets/characters/
```

## Minimum practical pack

A compact classic-chronicle pack can use these files:

```text
common/human/male/warrior.webp
common/human/male/mage.webp
common/human/female/warrior.webp
common/human/female/mage.webp
common/elf/male/warrior.webp
common/elf/male/mage.webp
common/elf/female/warrior.webp
common/elf/female/mage.webp
common/dark_elf/male/warrior.webp
common/dark_elf/male/mage.webp
common/dark_elf/female/warrior.webp
common/dark_elf/female/mage.webp
common/orc/male/warrior.webp
common/orc/male/mage.webp
common/orc/female/warrior.webp
common/orc/female/mage.webp
common/dwarf/male/default.webp
common/dwarf/female/default.webp
common/kamael/male/default.webp
common/kamael/female/default.webp
common/fallback/neutral/default.webp
```

Files for Ertheia and Sylph are optional and follow the same layout. A server-specific pack goes under `servers/{server_id}/` and has priority over common images.

Recommended format is WebP. Keep all images in a set at the same aspect ratio; square images are the simplest choice. The themes crop images with `object-fit: cover`.

See `docs/GAME_ASSETS.md` for the complete fallback chain and supported extensions.
