<?php

/**
 * Included by mysql-fixtures.php inside disposable local MySQL databases.
 * Requires the exact Composer lock dependencies but no running CMS server.
 * Never passes real user credentials, database hosts or production data.
 */
if (getenv('KAEV_UNIVERSAL_MYSQL_TEST') !== '1' || ! isset($pdo, $databaseNames)) {
    throw new RuntimeException('Read-only parity requires the isolated MySQL fixture harness.');
}
$autoload = __DIR__.'/../../../vendor/autoload.php';
if (! is_file($autoload)) {
    throw new RuntimeException('Composer dependencies are required for live driver parity.');
}
require_once $autoload;

use App\Services\GameAccounts\MobiusLoginDriver;
use App\Services\GameAccounts\MobiusPasswordEncoder;
use App\Services\GameAccounts\RusacisLoginDriver;
use App\Services\GameAccounts\RusacisPasswordEncoder;
use App\Services\GameAccounts\UniversalLoginAccountReader;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\PreparedGameAccount;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Database\MySqlConnection;

$profiles = (new BundledProfileCatalog)->all();
$reader = new UniversalLoginAccountReader;
foreach ($databaseNames as $type => $databaseName) {
    $pdo->exec("USE `$databaseName`");
    $connection = new MySqlConnection($pdo, $databaseName, '', [
        'driver' => 'mysql', 'database' => $databaseName, 'prefix' => '',
    ]);
    $legacy = $type === 'rusacis'
        ? new RusacisLoginDriver(new RusacisPasswordEncoder)
        : new MobiusLoginDriver(new MobiusPasswordEncoder);
    $ids = $type === 'rusacis' ? ['rusacis'] : ['l2j_mobius', 'l2j_mobius_legacy'];
    foreach ($ids as $profileId) {
        $profile = $profiles['login:'.$profileId];
        $diagnostics = $reader->diagnose($connection, $profile);
        if ($diagnostics !== ['missing_required' => [], 'missing_optional' => []]) {
            throw new RuntimeException("Unexpected schema gap in fixture $profileId: ".json_encode($diagnostics));
        }
        foreach (['alice', 'bob', 'missing', "alice' OR 1=1 --"] as $login) {
            $before = $legacy->summary($connection, $login);
            $after = $reader->summary($connection, $profile, $login);
            if ($before !== $after) {
                throw new RuntimeException("Summary parity mismatch in $profileId for $login");
            }
        }
        $bob = $reader->summary($connection, $profile, 'bob');
        if ($bob === null || $bob['status'] !== 'blocked' || $bob['last_active'] <= 0) {
            throw new RuntimeException("Blocked account state not detected: $profileId");
        }
        $email = $type === 'rusacis' ? 'not-read-by-game' : 'alice@example.invalid';
        $credential = $type === 'rusacis' ? '$2a$10$INVALID_FIXTURE_ONLY' : 'fixture';
        $cases = [
            new PreparedGameAccount('alice', $credential, $email),
            new PreparedGameAccount('alice', 'invalid-credential', $email),
            new PreparedGameAccount('absent', $credential, $email),
            new PreparedGameAccount('alice', $credential, 'mismatched@example.invalid'),
        ];
        foreach ($cases as $case) {
            $old = $legacy->inspect($connection, $case);
            $new = $reader->inspect($connection, $profile, $case);
            if ($old !== $new) {
                throw new RuntimeException("Prepared credential parity mismatch in $profileId for {$case->login}");
            }
        }
        if ($reader->inspect($connection, $profile, $cases[1]) !== ExternalGameAccountState::Conflict) {
            throw new RuntimeException('Invalid prepared credential must not match.');
        }
        $unsafe = $profile;
        $unsafe['config']['accounts']['fields']['login'] = 'login` OR 1=1';
        try {
            $reader->summary($connection, $unsafe, 'alice');
            throw new RuntimeException('Unsafe SQL column was accepted.');
        } catch (ProfileValidationException) {
            // Expected: rejected before touching game data.
        }
        echo "OK: $profileId SQL read-only parity (summary, inspection, injection)\n";
    }

    // Optional game metadata is not required for account lookups. The old
    // PHP reader cannot handle this reduced schema, so compare explicitly.
    $optionalColumn = $type === 'rusacis' ? 'last_active' : 'lastactive';
    $pdo->exec("ALTER TABLE `accounts` DROP COLUMN `$optionalColumn`");
    $diagnostics = $reader->diagnose($connection, $profiles['login:'.$ids[0]]);
    if (! in_array('accounts.'.$optionalColumn, $diagnostics['missing_optional'], true) || $diagnostics['missing_required'] !== []) {
        throw new RuntimeException('Missing optional column not reported correctly.');
    }
    $account = $reader->summary($connection, $profiles['login:'.$ids[0]], 'alice');
    if ($account === null || $account['last_active'] !== 0) {
        throw new RuntimeException('Missing optional activity must not break lookup.');
    }
    $statusColumn = $type === 'rusacis' ? 'access_level' : 'accessLevel';
    $pdo->exec("ALTER TABLE `accounts` DROP COLUMN `$statusColumn`");
    $account = $reader->summary($connection, $profiles['login:'.$ids[0]], 'alice');
    if ($account === null || $account['status'] !== 'unknown') {
        throw new RuntimeException('Absent access field must not masquerade as active.');
    }
    if ($type !== 'rusacis') {
        // Mobius account identity includes the email. Do not silently accept
        // a matching stored hash if the configured email column vanished.
        $pdo->exec('ALTER TABLE `accounts` DROP COLUMN `email`');
        $emailMissing = new PreparedGameAccount('alice', 'fixture', 'alice@example.invalid');
        if ($reader->inspect($connection, $profiles['login:'.$ids[0]], $emailMissing) !== ExternalGameAccountState::Conflict) {
            throw new RuntimeException('Missing mapped email must cause a conflict.');
        }
    }
    echo "OK: $type missing optional LoginServer metadata\n";
}
