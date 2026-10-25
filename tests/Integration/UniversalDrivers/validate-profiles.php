<?php

/** Dependency-free smoke check; run: php tests/Integration/UniversalDrivers/validate-profiles.php */
require __DIR__.'/../../../app/Support/UniversalDrivers/ProfileFormat.php';
require __DIR__.'/../../../app/Support/UniversalDrivers/ProfileValidationException.php';
require __DIR__.'/../../../app/Support/UniversalDrivers/ProfileFormatMigrator.php';
require __DIR__.'/../../../app/Support/UniversalDrivers/ProfileValidator.php';
require __DIR__.'/../../../app/Support/UniversalDrivers/BundledProfileCatalog.php';

use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;

$profiles = (new BundledProfileCatalog)->all();
if (count($profiles) !== 5) {
    throw new RuntimeException('Expected five bundled LoginServer/GameServer profiles.');
}
$validator = new ProfileValidator;
foreach ($profiles as $profile) {
    $validator->validate($profile);
    if ($validator->fromJson(json_encode($profile, JSON_THROW_ON_ERROR)) !== $profile) {
        throw new RuntimeException('Round-trip validation failed.');
    }
}
$negative = [
    static function (array &$p): void { $p['config']['characters']['table'] = 'characters` OR 1=1'; },
    static function (array &$p): void { $p['config']['characters']['fields']['hero']['operator'] = 'raw_sql'; },
    static function (array &$p): void { $p['config']['features']['heroes']['query'] = 'select * from accounts'; },
    static function (array &$p): void { $p['config']['database']['dialect'] = 'sqlserver'; },
    static function (array &$p): void { $p['format_version'] = 100; },
];
foreach ($negative as $mutate) {
    $bad = $profiles['game:rusacis'];
    $mutate($bad);
    try {
        $validator->validate($bad);
    } catch (ProfileValidationException) {
        continue;
    }
    throw new RuntimeException('Unsafe profile was accepted.');
}
foreach (['', '{', '[]', str_repeat('x', 262145)] as $badJson) {
    try {
        $validator->fromJson($badJson);
    } catch (ProfileValidationException) {
        continue;
    }
    throw new RuntimeException('Unsafe JSON was accepted.');
}
printf("OK: %d bundled profiles and 9 negative cases; no application bootstrap/DB used.\n", count($profiles));
