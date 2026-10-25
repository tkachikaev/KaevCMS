<?php

/**
 * Part of mysql-fixtures.php: disposable, localhost-only MySQL, Composer vendor.
 * Writer remains disconnected from live CMS resolvers.
 */
if (getenv('KAEV_UNIVERSAL_MYSQL_TEST') !== '1' || ! isset($pdo, $databaseNames)) {
    throw new RuntimeException('Universal LoginServer write parity needs isolated MySQL fixtures.');
}
require_once __DIR__.'/../../../vendor/autoload.php';

use App\Services\GameAccounts\MobiusLoginDriver;
use App\Services\GameAccounts\MobiusPasswordEncoder;
use App\Services\GameAccounts\RusacisLoginDriver;
use App\Services\GameAccounts\RusacisPasswordEncoder;
use App\Services\GameAccounts\UniversalLoginAccountReader;
use App\Services\GameAccounts\UniversalLoginAccountWriter;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\PreparedGameAccount;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;

$profiles = (new BundledProfileCatalog)->all();
$writer = new UniversalLoginAccountWriter;
$reader = new UniversalLoginAccountReader;
foreach ($databaseNames as $type => $name) {
    $pdo->exec("USE `$name`");
    $pdo->exec('ALTER TABLE `accounts` MODIFY `login` VARCHAR(255) NOT NULL, ADD UNIQUE KEY `uk_login_i5` (`login`)');
    $db = new MySqlConnection($pdo, $name, '', ['driver' => 'mysql', 'database' => $name, 'prefix' => '']);
    $legacy = $type === 'rusacis'
        ? new RusacisLoginDriver(new RusacisPasswordEncoder)
        : new MobiusLoginDriver(new MobiusPasswordEncoder);
    foreach (($type === 'rusacis' ? ['rusacis'] : ['l2j_mobius', 'l2j_mobius_legacy']) as $id) {
        $profile = $profiles['login:'.$id];
        $hash = $writer->preparePassword($profile, 'Testing123');
        if (! $writer->acceptsCredential($profile, $hash) || ! $legacy->acceptsCredential($hash)) {
            throw new RuntimeException("Prepared password format is incompatible for $id");
        }
        if ($id === 'rusacis') {
            if (! str_starts_with($hash, '$2a$10$') || ! password_verify('Testing123', $hash)) {
                throw new RuntimeException('RUSaCis BCrypt $2a$ proof is invalid.');
            }
        } elseif ($hash !== $legacy->encodePassword('Testing123')) {
            throw new RuntimeException('Mobius SHA1/Base64 password parity failed.');
        }
        $login = $id === 'rusacis' ? 'testRusacis' : ($id === 'l2j_mobius' ? 'testMobius' : 'testLegacy');
        $email = 'test@example.invalid';
        $prepared = new PreparedGameAccount($login, $hash, $email);
        if ($reader->inspect($db, $profile, $prepared) !== ExternalGameAccountState::Missing) {
            throw new RuntimeException('A new login should not exist yet.');
        }
        $writer->create($db, $profile, $prepared);
        if ($reader->inspect($db, $profile, $prepared) !== ExternalGameAccountState::Matching
            || $legacy->inspect($db, $prepared) !== ExternalGameAccountState::Matching) {
            throw new RuntimeException('Created game account does not match immutable recovery proof.');
        }
        // Simulate lost client response after the committed insert: a retry
        // must fail at the unique index; read-only inspection resolves state.
        try {
            $writer->create($db, $profile, $prepared);
            throw new RuntimeException('Repeated account creation inserted duplicate.');
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
        }
        if ($reader->inspect($db, $profile, $prepared) !== ExternalGameAccountState::Matching) {
            throw new RuntimeException('Account creation recovery must match original proof.');
        }
        $next = $writer->preparePassword($profile, 'Changed123');
        if (! $writer->changePreparedPassword($db, $profile, $login, $hash, $next)
            || ! $writer->changePreparedPassword($db, $profile, $login, $hash, $next)) {
            throw new RuntimeException('Password change/retry was not idempotent.');
        }
        if ($reader->inspect($db, $profile, new PreparedGameAccount($login, $next, $email)) !== ExternalGameAccountState::Matching) {
            throw new RuntimeException('Stored replacement password does not match proof.');
        }
        if ($writer->changePreparedPassword($db, $profile, 'missingAccount', $hash, $next) !== false) {
            throw new RuntimeException('Password change must not create unknown account.');
        }
        try {
            $writer->changePreparedPassword($db, $profile, $login, $hash, $writer->preparePassword($profile, 'Other123'));
            throw new RuntimeException('A stale password change unexpectedly overwrote an account.');
        } catch (RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'differs')) {
                throw $e;
            }
        }
        $bad = $profile;
        $bad['config']['capabilities'] = ['account_lookup'];
        try {
            $writer->create($db, $bad, $prepared);
            throw new RuntimeException('Creation without capability was accepted.');
        } catch (ProfileValidationException) {
            // Expected.
        }
        echo "OK: $id write parity, unique index, immutable BCrypt/SHA1, retry, conflict and ownership proof\n";
    }
    // A valid JSON mapping alone must not enable unsafe writes into a table
    // without an enforced unique login key.
    $pdo->exec('ALTER TABLE `accounts` DROP INDEX `uk_login_i5`');
    $profile = $profiles['login:'.($type === 'rusacis' ? 'rusacis' : 'l2j_mobius')];
    try {
        $writer->create($db, $profile, new PreparedGameAccount('indexMissing', $writer->preparePassword($profile, 'Valid123'), 'test@example.invalid'));
        throw new RuntimeException('Unsafe account creation without unique login index was accepted.');
    } catch (ProfileValidationException) {
        // Expected.
    }
}
