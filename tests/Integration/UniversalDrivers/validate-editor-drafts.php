<?php

/** Dependency-free editor smoke test. Does not contact a game database. */
foreach (['ProfileFormat', 'ProfileValidationException', 'ProfileFormatMigrator', 'ProfileValidator', 'ProfileEditorDraft'] as $class) {
    require __DIR__.'/../../../app/Support/UniversalDrivers/'.$class.'.php';
}

use App\Support\UniversalDrivers\ProfileEditorDraft;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;

$editor = new ProfileEditorDraft;
$validator = new ProfileValidator;
foreach (glob(__DIR__.'/../../../driver-profiles/bundled/*.json') as $file) {
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
echo "OK: five unchanged bundled profiles, two blank driver forms and malformed input rejection.\n";
