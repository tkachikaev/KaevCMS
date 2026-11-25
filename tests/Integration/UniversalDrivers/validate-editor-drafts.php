<?php

/** Dependency-free editor smoke test. Does not contact a game database. */
foreach (['ProfileFormat', 'ProfileValidationException', 'ProfileFormatMigrator', 'GameCharacterFilters', 'ProfileValidator', 'ProfileEditorDraft'] as $class) {
    require __DIR__.'/../../../app/Support/UniversalDrivers/'.$class.'.php';
}

use App\Support\UniversalDrivers\ProfileEditorDraft;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;

$editor = new ProfileEditorDraft;
$validator = new ProfileValidator;
$files = glob(__DIR__.'/../../../driver-profiles/bundled/*.json') ?: [];
if ($files === []) {
    throw new RuntimeException('No bundled profiles found.');
}
foreach ($files as $file) {
    $original = $validator->fromJson(file_get_contents($file));
    $clean = $editor->clean($original);
    $validator->validate($clean);
    if ($original !== $clean) {
        throw new RuntimeException('The editor changed an untouched bundled profile: '.basename($file));
    }
}
foreach (['login', 'game'] as $type) {
    $new = $editor->blank($type);
    $new['name'] = 'Offline test';
    $new['id'] = 'offline-test';
    $validator->validate($editor->clean($new));
}
$bad = $editor->blank('game');
$bad['config']['requirements'] = 'SELECT * FROM characters';
try {
    $editor->clean($bad);
    throw new RuntimeException('Malformed editor input was accepted.');
} catch (ProfileValidationException) {
    // Expected: prevent tampering with the nested form payload.
}
printf("OK: %d unchanged bundled profiles, two blank driver forms and malformed input rejection.\n", count($files));
