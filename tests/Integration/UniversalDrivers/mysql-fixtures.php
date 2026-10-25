<?php

/**
 * MySQL/MariaDB fixture and read-only LoginServer parity tests.
 * Runs only with explicit test flag on localhost and creates unique disposable test databases.
 * Required environment: KAEV_UNIVERSAL_MYSQL_TEST=1, KAEV_MYSQL_TEST_USER,
 * KAEV_MYSQL_TEST_PASSWORD (empty allowed), KAEV_MYSQL_TEST_HOST=127.0.0.1.
 */
if (getenv('KAEV_UNIVERSAL_MYSQL_TEST') !== '1') {
    fwrite(STDERR, "Refusing to touch databases without KAEV_UNIVERSAL_MYSQL_TEST=1.\n");
    exit(2);
}
$host = getenv('KAEV_MYSQL_TEST_HOST') ?: '127.0.0.1';
if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "MySQL test harness accepts only localhost.\n");
    exit(2);
}
if (! extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql extension is required.\n");
    exit(2);
}
$port = (int) (getenv('KAEV_MYSQL_TEST_PORT') ?: '3306');
if ($port < 1 || $port > 65535) {
    throw new RuntimeException('Invalid MySQL test port.');
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    getenv('KAEV_MYSQL_TEST_USER') ?: 'root',
    getenv('KAEV_MYSQL_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
// Unique, process-owned databases. Never drop or overwrite a pre-existing DB.
$suffix = bin2hex(random_bytes(5));
$databaseNames = ['rusacis' => 'kaev_ud_rusacis_'.$suffix, 'l2j_mobius' => 'kaev_ud_mobius_'.$suffix];
$created = [];
try {
foreach ($databaseNames as $fixture => $db) {
    $pdo->exec("CREATE DATABASE `$db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created[] = $db;
    $pdo->exec("USE `$db`");
    $fixtureSql = file_get_contents(__DIR__.'/../../Fixtures/UniversalDrivers/'.$fixture.'.sql');
    if ($fixtureSql === false) {
        throw new RuntimeException('Missing test fixture SQL.');
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $fixtureSql) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    $characterId = $fixture === 'rusacis' ? 'obj_Id' : 'charId';
    $query = $pdo->prepare('SELECT c.char_name FROM characters c WHERE c.account_name = ? AND c.accesslevel = 0 AND c.deletetime = 0 ORDER BY c.level DESC');
    $query->execute(['alice']);
    $actual = $query->fetchAll(PDO::FETCH_COLUMN);
    if ($actual !== ['AliceKnight']) {
        throw new RuntimeException("Account ownership/deleted filter mismatch: $fixture");
    }
    $query->execute(['bob']);
    if ($query->fetchAll(PDO::FETCH_COLUMN) !== ['BobMage']) {
        throw new RuntimeException("Account ownership mismatch: $fixture");
    }
    $heroKey = $fixture === 'rusacis' ? 'char_id' : 'charId';
    $query = $pdo->query("SELECT c.char_name FROM characters c JOIN heroes h ON h.`$heroKey` = c.`$characterId` WHERE c.char_name = 'BobMage'");
    if ($query->fetchColumn() !== 'BobMage') {
        throw new RuntimeException("Hero relation mismatch: $fixture");
    }
    $query = $pdo->query("SELECT castle.id, cd.clan_name FROM castle JOIN clan_data cd ON cd.hasCastle = castle.id WHERE castle.id = 1");
    if ($query->fetch(PDO::FETCH_ASSOC) !== ['id' => 1, 'clan_name' => 'TestClan']) {
        throw new RuntimeException("Castle-clan relation mismatch: $fixture");
    }
    echo "OK: $fixture fixtures and MySQL relations\n";
}

require __DIR__.'/mysql-game-character-parity.php';
require __DIR__.'/mysql-login-parity.php';
} finally {
    // Only remove databases created by this exact invocation.
    foreach ($created as $db) {
        $pdo->exec("DROP DATABASE `$db`");
    }
}
