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
use App\Support\UniversalDrivers\LoginProfileEditor;
use App\Support\UniversalDrivers\ProfileEditorDraft;
use App\Support\UniversalDrivers\ProfileFormat;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
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
    if ($type === 'l2j_mobius') {
        // Unsalted hex schemes go through the same create / recovery / change flow on a real server.
        foreach (['whirlpool_hex' => 'hexWhirlpool', 'sha1_hex' => 'hexSha1', 'sha256_hex' => 'hexSha256', 'md5_hex' => 'hexMd5'] as $scheme => $hexLogin) {
            $hexProfile = $profiles['login:l2j_mobius'];
            $hexProfile['config']['password'] = ['scheme' => $scheme];
            $algorithm = ProfileFormat::HEX_PASSWORD_SCHEMES[$scheme][0];
            $hexHash = $writer->preparePassword($hexProfile, 'Testing123');
            if ($hexHash !== hash($algorithm, 'Testing123')) {
                throw new RuntimeException("$scheme digest differs from the reference implementation.");
            }
            $hexPrepared = new PreparedGameAccount($hexLogin, $hexHash, 'hex@example.invalid');
            $writer->create($db, $hexProfile, $hexPrepared);
            if ($reader->inspect($db, $hexProfile, $hexPrepared) !== ExternalGameAccountState::Matching) {
                throw new RuntimeException("$scheme account does not match its recovery proof.");
            }
            $stored = $pdo->query("SELECT password FROM accounts WHERE login = '$hexLogin'")->fetchColumn();
            if ($stored !== $hexHash) {
                throw new RuntimeException("$scheme hash was stored altered or truncated.");
            }
            $hexNext = $writer->preparePassword($hexProfile, 'Changed123');
            if (! $writer->changePreparedPassword($db, $hexProfile, $hexLogin, $hexHash, $hexNext)
                || ! $writer->changePreparedPassword($db, $hexProfile, $hexLogin, $hexHash, $hexNext)) {
                throw new RuntimeException("$scheme password change was not idempotent.");
            }
            echo "OK: $scheme create, read-back, inspect and idempotent change on a real server\n";
        }
        // A password column that cannot hold the digest is refused before any write.
        $pdo->exec("DELETE FROM accounts WHERE login LIKE 'hex%'");
        $pdo->exec('ALTER TABLE `accounts` MODIFY `password` VARCHAR(45) NULL');
        $narrow = $profiles['login:l2j_mobius'];
        $narrow['config']['password'] = ['scheme' => 'whirlpool_hex'];
        try {
            $writer->create($db, $narrow, new PreparedGameAccount('narrowColumn', $writer->preparePassword($narrow, 'Testing123'), 'n@example.invalid'));
            throw new RuntimeException('A 128-character hash was written into VARCHAR(45).');
        } catch (ProfileValidationException $e) {
            if (! str_contains($e->getMessage(), 'holds 45 characters')) {
                throw $e;
            }
        }
        $sha1Profile = $profiles['login:l2j_mobius'];
        $writer->create($db, $sha1Profile, new PreparedGameAccount('narrowOk', $writer->preparePassword($sha1Profile, 'Testing123'), 'n@example.invalid'));
        $pdo->exec('ALTER TABLE `accounts` MODIFY `password` VARCHAR(255) NULL');
        echo "OK: password column width guard (VARCHAR(45) refuses Whirlpool, accepts SHA-1/Base64)\n";
    }
    if ($type === 'l2j_mobius') {
        // A driver assembled the way the editor assembles it: own table and column names, MD5.
        $pdo->exec('CREATE TABLE `site_players` (`user_name` VARCHAR(45) NOT NULL, `pass_hash` VARCHAR(32) NOT NULL, `mail` VARCHAR(100) NULL, UNIQUE KEY `uk_user` (`user_name`))');
        $draft = (new ProfileEditorDraft)->blank('login');
        $draft['id'] = 'custom-md5-site';
        $draft['name'] = 'Custom MD5 site';
        $draft['config']['capabilities'] = ['account_lookup', 'account_creation', 'password_change'];
        $draft['config']['accounts'] = [
            'table' => 'site_players',
            'fields' => ['login' => 'user_name', 'password' => 'pass_hash', 'email' => 'mail'],
            'ownership' => ['identity' => 'login', 'column' => 'user_name'],
            'create' => ['columns' => ['user_name' => 'login', 'pass_hash' => 'credential', 'mail' => 'email']],
        ];
        $draft['config']['password'] = ['scheme' => 'md5_hex'];
        $custom = (new LoginProfileEditor)->prepare($draft, 'accounts');
        (new ProfileValidator)->validate($custom);
        $customHash = $writer->preparePassword($custom, 'Testing123');
        $customAccount = new PreparedGameAccount('customUser', $customHash, 'custom@example.invalid');
        if ($reader->inspect($db, $custom, $customAccount) !== ExternalGameAccountState::Missing) {
            throw new RuntimeException('A custom-schema login should not exist yet.');
        }
        $writer->create($db, $custom, $customAccount);
        if ($reader->inspect($db, $custom, $customAccount) !== ExternalGameAccountState::Matching) {
            throw new RuntimeException('Custom-schema account does not match its recovery proof.');
        }
        $row = $pdo->query("SELECT pass_hash, mail FROM site_players WHERE user_name = 'customUser'")->fetch(PDO::FETCH_ASSOC);
        if ($row !== ['pass_hash' => md5('Testing123'), 'mail' => 'custom@example.invalid']) {
            throw new RuntimeException('Custom-schema row was stored differently from the profile.');
        }
        $customNext = $writer->preparePassword($custom, 'Changed123');
        if (! $writer->changePreparedPassword($db, $custom, 'customUser', $customHash, $customNext)) {
            throw new RuntimeException('Custom-schema password change failed.');
        }
        $pdo->exec('DROP TABLE `site_players`');
        echo "OK: custom-schema MD5 driver built like the editor builds it (own table and columns)\n";
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
