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
    static function (array &$p): void { $p['config']['features']['heroes']['claimed_column'] = 'played; DROP TABLE heroes'; },
    static function (array &$p): void { $p['config']['features']['castles']['leader_relation'] = 'unregistered_relation'; },
    static function (array &$p): void { $p['config']['features']['castles']['clan_columns']['name'] = 'clan_name FROM users'; },
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
// These fields are used by the real connection tester to approve a profile.
// A valid JSON must not claim compatibility while omitting the mandatory
// character/account owner keys or a mandatory filter column from preflight.
$contractNegative = [
    ['login:l2j_mobius', static function (array &$p): void { $p['config']['requirements'][0]['required'] = false; }],
    ['login:rusacis', static function (array &$p): void { $p['config']['requirements'][0]['columns'] = ['password']; }],
    ['login:rusacis', static function (array &$p): void { $p['config']['requirements'][0]['columns'] = ['login']; }],
    ['login:rusacis', static function (array &$p): void { $p['config']['accounts']['ownership']['column'] = 'password'; }],
    ['game:rusacis', static function (array &$p): void { $p['config']['requirements'][0]['required'] = false; }],
    ['game:rusacis', static function (array &$p): void { $p['config']['requirements'][0]['columns'] = ['obj_Id', 'char_name']; }],
    ['game:rusacis', static function (array &$p): void { $p['config']['requirements'][0]['columns'] = ['account_name', 'char_name']; }],
    ['game:rusacis', static function (array &$p): void { $p['config']['requirements'][0]['columns'] = ['account_name', 'obj_Id']; }],
    ['game:rusacis', static function (array &$p): void {
        $p['config']['requirements'][0]['columns'] = array_values(array_diff($p['config']['requirements'][0]['columns'], ['deletetime']));
    }],
];
foreach ($contractNegative as [$identity, $mutate]) {
    $bad = $profiles[$identity];
    $mutate($bad);
    try {
        $validator->validate($bad);
    } catch (ProfileValidationException) {
        continue;
    }
    throw new RuntimeException('A profile with an unverified primary key/filter was accepted: '.$identity);
}
printf("OK: %d bundled profiles, 12 unsafe-input and %d primary-contract negative cases; offline.\n", count($profiles), count($contractNegative));
