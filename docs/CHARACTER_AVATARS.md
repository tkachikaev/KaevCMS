# Character avatar image pack / Пакет аватаров персонажей

KaevCMS does not include character artwork. Keep your external images under:

```text
public/game-assets/characters/{profile}/
```

Supported item/asset profiles currently include:

```text
interlude
classic
high-five
shine-maker
```

Use the same hierarchy inside every profile:

```text
characters/{profile}/human/male/warrior.webp
characters/{profile}/human/male/mage.webp
characters/{profile}/human/female/warrior.webp
characters/{profile}/human/female/mage.webp
characters/{profile}/elf/male/warrior.webp
characters/{profile}/elf/male/mage.webp
characters/{profile}/elf/female/warrior.webp
characters/{profile}/elf/female/mage.webp
characters/{profile}/dark_elf/male/warrior.webp
characters/{profile}/dark_elf/male/mage.webp
characters/{profile}/dark_elf/female/warrior.webp
characters/{profile}/dark_elf/female/mage.webp
characters/{profile}/orc/male/warrior.webp
characters/{profile}/orc/male/mage.webp
characters/{profile}/orc/female/warrior.webp
characters/{profile}/orc/female/mage.webp
characters/{profile}/dwarf/male/default.webp
characters/{profile}/dwarf/female/default.webp
characters/{profile}/kamael/male/default.webp
characters/{profile}/kamael/female/default.webp
characters/{profile}/ertheia/male/warrior.webp
characters/{profile}/ertheia/male/mage.webp
characters/{profile}/ertheia/female/warrior.webp
characters/{profile}/ertheia/female/mage.webp
characters/{profile}/sylph/male/default.webp
characters/{profile}/sylph/female/default.webp
characters/{profile}/fallback/male/default.webp
characters/{profile}/fallback/female/default.webp
characters/{profile}/fallback/neutral/default.webp
```

WebP is recommended; PNG, JPG, and JPEG are also supported. Use square images of the same dimensions.

Owner overrides under `public/uploads/game-assets/characters` have priority: `servers/{server_id}` first, then `common`, then the external profile pack.

---

Изображения не входят в релизные архивы KaevCMS. Положите их в `public/game-assets/characters/{profile}` по указанной выше иерархии. Пустые папки уже созданы для Interlude, Classic, High Five и Shine Maker. Серверные и общие переопределения из `public/uploads/game-assets/characters` имеют приоритет.
