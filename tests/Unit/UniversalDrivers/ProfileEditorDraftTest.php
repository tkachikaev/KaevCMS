<?php

namespace Tests\Unit\UniversalDrivers;

use App\Support\UniversalDrivers\ProfileEditorDraft;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
use PHPUnit\Framework\TestCase;

final class ProfileEditorDraftTest extends TestCase
{
    public function test_all_bundled_profiles_survive_editor_roundtrip_unchanged(): void
    {
        $validator = new ProfileValidator;
        $editor = new ProfileEditorDraft;
        foreach (glob(dirname(__DIR__, 3).'/driver-profiles/bundled/*.json') as $file) {
            $original = $validator->fromJson(file_get_contents($file));
            $clean = $editor->clean($original);
            $validator->validate($clean);
            $this->assertSame($original, $clean, basename($file));
        }
    }

    public function test_blank_login_and_game_schemas_can_be_saved_after_naming(): void
    {
        $validator = new ProfileValidator;
        $editor = new ProfileEditorDraft;
        foreach (['login', 'game'] as $type) {
            $profile = $editor->blank($type);
            $profile['id'] = 'test-'.$type;
            $profile['name'] = 'Test '.$type;
            $validator->validate($editor->clean($profile));
            $this->assertSame($type, $profile['type']);
        }
    }

    public function test_malformed_editor_structure_is_rejected(): void
    {
        $editor = new ProfileEditorDraft;
        $profile = $editor->blank('game');
        $profile['config']['requirements'] = 'DROP TABLE characters';
        $this->expectException(ProfileValidationException::class);
        $editor->clean($profile);
    }
}
