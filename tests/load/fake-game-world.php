<?php

/**
 * Fills an EMPTY copy of the L2JMobius Interlude schema with generated accounts, characters,
 * clans, castles and heroes, so the player cabinet and statistics can be load-tested without
 * a real game server. Dependency-free (PDO only).
 *
 *   php tests/load/fake-game-world.php --config=storage/load-test/game-db.json --accounts=1000
 *
 * The config file holds game_db_host, game_db_port, game_db_database, game_db_username and
 * game_db_password. Accounts are named lt_00001 and up; existing generated rows are replaced.
 * The database name must contain "load" or "test": a live game database is never touched.
 */
$options = getopt('', ['config:', 'accounts::', 'seed::']);
$config = json_decode((string) @file_get_contents((string) ($options['config'] ?? '')), true);
if (! is_array($config)) {
    fwrite(STDERR, "Pass --config=<json file> with the game database connection.\n");
    exit(2);
}

$database = (string) $config['game_db_database'];
if (preg_match('/load|test/i', $database) !== 1) {
    fwrite(STDERR, "Refusing to write to [{$database}]: the name must contain \"load\" or \"test\".\n");
    exit(2);
}

$accounts = max(1, (int) ($options['accounts'] ?? 1000));
mt_srand((int) ($options['seed'] ?? 42));
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['game_db_host'], (int) $config['game_db_port'], $database),
    (string) $config['game_db_username'],
    (string) $config['game_db_password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

// Interlude classes by race: [race => [class ids]] (first, second and third professions).
$classes = [
    0 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 88, 89, 90, 91, 92, 93, 94, 95, 96, 97, 98],
    1 => [18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 99, 100, 101, 102, 103, 104, 105],
    2 => [31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 106, 107, 108, 109, 110, 111, 112],
    3 => [44, 45, 46, 47, 48, 49, 50, 51, 52, 113, 114, 115, 116],
    4 => [53, 54, 55, 56, 57, 117, 118],
];
$syllables = ['ka', 'ev', 'ra', 'th', 'or', 'el', 'dra', 'mir', 'zan', 'tor', 'lin', 'vex', 'shi', 'gar', 'nu', 'ul', 'bel', 'ash', 'kor', 'ien'];
$name = static function (int $seed) use ($syllables): string {
    $value = '';
    for ($part = 0; $part < 3; $part++) {
        $value .= $syllables[($seed >> ($part * 4)) % count($syllables)];
    }

    return ucfirst($value).$seed;
};
$insert = static function (PDO $pdo, string $table, array $rows): void {
    foreach (array_chunk($rows, 500) as $chunk) {
        $columns = array_keys($chunk[0]);
        $placeholders = '('.implode(',', array_fill(0, count($columns), '?')).')';
        $statement = $pdo->prepare(sprintf(
            'INSERT INTO `%s` (`%s`) VALUES %s',
            $table,
            implode('`,`', $columns),
            implode(',', array_fill(0, count($chunk), $placeholders)),
        ));
        $statement->execute(array_merge(...array_map('array_values', $chunk)));
    }
};

$pdo->exec("DELETE FROM heroes; DELETE FROM castle; DELETE FROM clan_data; DELETE FROM characters WHERE account_name LIKE 'lt\\_%'; DELETE FROM accounts WHERE login LIKE 'lt\\_%'");

$now = time();
$accountRows = [];
$characterRows = [];
$characterId = 268_480_000;
for ($index = 1; $index <= $accounts; $index++) {
    $login = sprintf('lt_%05d', $index);
    $accountRows[] = [
        'login' => $login,
        'password' => base64_encode(sha1(bin2hex(random_bytes(8)), true)),
        'email' => null,
        'lastactive' => ($now - mt_rand(0, 86_400 * 60)) * 1000,
        'accessLevel' => 0,
        'lastIP' => '127.0.0.1',
        'lastServer' => 1,
    ];

    $count = mt_rand(1, 7);
    for ($slot = 0; $slot < $count; $slot++) {
        $race = array_rand($classes);
        $classId = $classes[$race][array_rand($classes[$race])];
        $level = $classId >= 88 ? mt_rand(76, 80) : ($classId % 9 === 0 ? mt_rand(1, 19) : mt_rand(20, 75));
        $characterId++;
        $characterRows[] = [
            'account_name' => $login,
            'charId' => $characterId,
            'char_name' => $name($characterId - 268_480_000),
            'level' => $level,
            'maxHp' => 100 + $level * 40, 'curHp' => 100 + $level * 40,
            'maxCp' => 50 + $level * 20, 'curCp' => 50 + $level * 20,
            'maxMp' => 60 + $level * 25, 'curMp' => 60 + $level * 25,
            'face' => mt_rand(0, 2), 'hairStyle' => mt_rand(0, 4), 'hairColor' => mt_rand(0, 3),
            'sex' => mt_rand(0, 1),
            'heading' => 0, 'x' => 83_400 + mt_rand(-500, 500), 'y' => 147_900 + mt_rand(-500, 500), 'z' => -3_400,
            'exp' => $level * $level * 10_000,
            'sp' => $level * 1_000,
            'karma' => 0,
            'pvpkills' => mt_rand(0, 3) === 0 ? mt_rand(0, 2_000) : 0,
            'pkkills' => mt_rand(0, 10) === 0 ? mt_rand(0, 300) : 0,
            'clanid' => mt_rand(0, 9) < 4 ? 1_000 + mt_rand(1, 60) : 0,
            'race' => $race,
            'classid' => $classId,
            'base_class' => $classId,
            'deletetime' => 0,
            'title' => '',
            'accesslevel' => 0,
            'online' => mt_rand(0, 19) === 0 ? 1 : 0,
            'onlinetime' => mt_rand(0, 3_000_000),
            'char_slot' => $slot,
            'lastAccess' => ($now - mt_rand(0, 86_400 * 30)) * 1000,
            'nobless' => $classId >= 88 && mt_rand(0, 4) === 0 ? 1 : 0,
            'createDate' => date('Y-m-d', $now - mt_rand(0, 86_400 * 365)),
        ];
    }
}
$insert($pdo, 'accounts', $accountRows);
$insert($pdo, 'characters', $characterRows);

$castles = ['Gludio', 'Dion', 'Giran', 'Oren', 'Aden', 'Innadril', 'Goddard', 'Rune', 'Schuttgart'];
$clanRows = [];
for ($clan = 1; $clan <= 60; $clan++) {
    $clanRows[] = [
        'clan_id' => 1_000 + $clan,
        'clan_name' => 'Clan'.$name($clan * 7),
        'clan_level' => mt_rand(1, 8),
        'reputation_score' => mt_rand(0, 50_000),
        'hasCastle' => $clan <= count($castles) ? $clan : 0,
        'leader_id' => $characterRows[array_rand($characterRows)]['charId'],
    ];
}
$insert($pdo, 'clan_data', $clanRows);
$insert($pdo, 'castle', array_map(
    static fn (int $index): array => ['id' => $index + 1, 'name' => $castles[$index], 'taxPercent' => 10, 'treasury' => mt_rand(0, 900_000_000)],
    array_keys($castles),
));

$heroes = [];
foreach (array_rand($characterRows, 30) as $key) {
    $heroes[] = ['charId' => $characterRows[$key]['charId'], 'class_id' => $characterRows[$key]['classid'], 'count' => 1, 'played' => 1, 'claimed' => 'true', 'message' => ''];
}
$insert($pdo, 'heroes', $heroes);

printf("accounts %d, characters %d, online %d, clans %d, castles %d, heroes %d\n",
    count($accountRows), count($characterRows), count(array_filter($characterRows, static fn (array $row): bool => $row['online'] === 1)),
    count($clanRows), count($castles), count($heroes));
