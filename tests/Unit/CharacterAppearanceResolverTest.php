<?php

namespace Tests\Unit;

use App\Services\GameAssets\CharacterAppearanceResolver;
use App\Services\GameAssets\GameAssetUrlResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class CharacterAppearanceResolverTest extends TestCase
{
    public function test_known_classes_resolve_to_broad_visual_archetypes(): void
    {
        $resolver = app(CharacterAppearanceResolver::class);

        $humanWarrior = $resolver->resolve(3, 0, 0, 1);
        $humanMage = $resolver->resolve(3, 0, 1, 10);
        $ertheiaMage = $resolver->resolve(3, 6, 1, 183);

        $this->assertSame('human', $humanWarrior['race_key']);
        $this->assertSame('male', $humanWarrior['gender_key']);
        $this->assertSame('warrior', $humanWarrior['archetype']);
        $this->assertSame('human/male/warrior', $humanWarrior['avatar_key']);

        $this->assertSame('mage', $humanMage['archetype']);
        $this->assertSame('human/female/mage', $humanMage['avatar_key']);

        $this->assertSame('ertheia', $ertheiaMage['race_key']);
        $this->assertSame('mage', $ertheiaMage['archetype']);
    }

    public function test_single_variant_races_and_unknown_values_use_safe_defaults(): void
    {
        $resolver = app(CharacterAppearanceResolver::class);

        $dwarf = $resolver->resolve(null, 4, 1, 53);
        $kamael = $resolver->resolve(null, 5, 0, 123);
        $sylph = $resolver->resolve(null, 7, 1, 9999);
        $unknownClass = $resolver->resolve(null, 0, 1, 9999);
        $unknownRace = $resolver->resolve(null, 999, 999, 9999);

        $this->assertSame('default', $dwarf['archetype']);
        $this->assertSame('dwarf/female/default', $dwarf['avatar_key']);
        $this->assertSame('default', $kamael['archetype']);
        $this->assertSame('kamael/male/default', $kamael['avatar_key']);
        $this->assertSame('default', $sylph['archetype']);
        $this->assertSame('default', $unknownClass['archetype']);
        $this->assertSame('human/female/default', $unknownClass['avatar_key']);
        $this->assertSame('unknown', $unknownRace['race_key']);
        $this->assertSame('neutral', $unknownRace['gender_key']);
        $this->assertSame('unknown/neutral/default', $unknownRace['avatar_key']);
    }

    public function test_server_pack_has_priority_and_common_pack_remains_a_fallback(): void
    {
        $root = storage_path('framework/testing/character-assets-'.Str::uuid());
        config()->set('cms.game_assets.uploads_path', $root);

        try {
            File::ensureDirectoryExists($root.'/characters/common/human/female');
            File::put($root.'/characters/common/human/female/mage.webp', 'common exact');

            File::ensureDirectoryExists($root.'/characters/servers/3/fallback/female');
            File::put($root.'/characters/servers/3/fallback/female/default.png', 'server fallback');

            $resolver = app(CharacterAppearanceResolver::class);
            $appearance = $resolver->resolve(3, 0, 1, 10);
            $commonOnly = $resolver->resolve(4, 0, 1, 10);

            $this->assertNotNull($appearance['avatar_url']);
            $this->assertStringEndsWith(
                '/uploads/game-assets/characters/servers/3/fallback/female/default.png',
                $appearance['avatar_url'],
            );
            $this->assertNotNull($commonOnly['avatar_url']);
            $this->assertStringEndsWith(
                '/uploads/game-assets/characters/common/human/female/mage.webp',
                $commonOnly['avatar_url'],
            );
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_avatar_paths_reject_traversal_and_absolute_keys(): void
    {
        $assets = app(GameAssetUrlResolver::class);

        $this->assertNull($assets->characterAvatar(3, '../../.env'));
        $this->assertNull($assets->characterAvatar(3, '/human/female/mage'));
        $this->assertNull($assets->characterAvatar(3, 'human/female/mage/'));
        $this->assertNull($assets->characterAvatar(3, 'human\\female\\..\\mage'));
    }
}
