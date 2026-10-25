<?php

/** Dependency-free writer/password-profile checks; does not open a DB. */
require __DIR__.'/../../../app/Support/UniversalDrivers/ProfileFormat.php';
require __DIR__.'/../../../app/Support/UniversalDrivers/ProfileValidationException.php';
require __DIR__.'/../../../app/Support/UniversalDrivers/ProfileFormatMigrator.php';
require __DIR__.'/../../../app/Support/UniversalDrivers/ProfileValidator.php';
require __DIR__.'/../../../app/Support/UniversalDrivers/BundledProfileCatalog.php';
require __DIR__.'/../../../app/Services/GameAccounts/UniversalLoginAccountWriter.php';
require __DIR__.'/../../../app/Services/GameAccounts/MobiusPasswordEncoder.php';
require __DIR__.'/../../../app/Services/GameAccounts/RusacisPasswordEncoder.php';

use App\Services\GameAccounts\MobiusPasswordEncoder;
use App\Services\GameAccounts\RusacisPasswordEncoder;
use App\Services\GameAccounts\UniversalLoginAccountWriter;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;

$validator = new ProfileValidator;
$writer = new UniversalLoginAccountWriter;
$profiles = (new BundledProfileCatalog)->all();
$checks = 0;
foreach (['l2j_mobius', 'l2j_mobius_legacy', 'rusacis'] as $id) {
    $p = $profiles['login:'.$id];
    $hash = $writer->preparePassword($p, 'TestPassword123');
    if (! $writer->acceptsCredential($p, $hash)) {
        throw new RuntimeException("$id: created credential not accepted");
    }
    if ($id === 'rusacis') {
        if (! str_starts_with($hash, '$2a$10$') || ! password_verify('TestPassword123', $hash)
            || ! (new RusacisPasswordEncoder)->encode('TestPassword123')) {
            throw new RuntimeException('Invalid BCrypt $2a$ format.');
        }
        $alteredCost = $p;
        $alteredCost['config']['password']['cost'] = 11;
        if ($writer->acceptsCredential($alteredCost, $hash)) {
            throw new RuntimeException('Wrong BCrypt cost was accepted.');
        }
    } elseif ($hash !== (new MobiusPasswordEncoder)->encode('TestPassword123')) {
        throw new RuntimeException("$id: SHA-1/Base64 parity failure");
    }
    if ($writer->acceptsCredential($p, 'not-a-password-hash')) {
        throw new RuntimeException("$id: malformed password accepted");
    }
    $checks += 2;
}

$base = $profiles['login:rusacis'];
$invalidMutations = [
    static function (array &$p): void {
        $p['config']['accounts']['create']['columns']['access_level'] = 'credential';
    },
    static function (array &$p): void {
        $p['config']['accounts']['create']['fixed_values']['access_level'] = 100;
    },
    static function (array &$p): void {
        $p['config']['accounts']['create']['fixed_values']['login'] = 'admin';
    },
    static function (array &$p): void {
        $p['config']['accounts']['create']['fixed_values']['unexpected_col'] = 0;
    },
    static function (array &$p): void {
        $p['config']['accounts']['create']['fixed_values']['last_active'] = ['php' => 'evil'];
    },
    static function (array &$p): void {
        $p['config']['accounts']['create']['fixed_values']['last_server'] = 'foo; DROP TABLE accounts';
        $p['config']['accounts']['create']['fixed_values']['login'] = 'foo';
    },
    static function (array &$p): void {
        $p['config']['accounts']['create']['columns']['password'] = 'email';
    },
];
foreach ($invalidMutations as $mutate) {
    $candidate = $base;
    $mutate($candidate);
    try {
        $validator->validate($candidate);
    } catch (ProfileValidationException) {
        $checks++;

        continue;
    }
    throw new RuntimeException('Unsafe account creation profile was accepted.');
}

$valid = $base;
$valid['config']['accounts']['create']['fixed_values'] = ['access_level' => 0, 'last_active' => 0];
$validator->validate($valid);
$checks++;
printf("OK: %d writer/password/constant safety checks; no DB or Composer.\n", $checks);
