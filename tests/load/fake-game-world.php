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

// Interlude classes by race and profession tier: base (1-19), first (20-39), second (40-75), third (76-80).
$classes = [
    0 => [[0, 10], [1, 4, 7, 11, 15], [2, 3, 5, 6, 8, 9, 12, 13, 14, 16, 17], range(88, 98)],
    1 => [[18, 25], [19, 22, 26, 29], [20, 21, 23, 24, 27, 28, 30], range(99, 105)],
    2 => [[31, 38], [32, 35, 39, 42], [33, 34, 36, 37, 40, 41, 43], range(106, 112)],
    3 => [[44, 49], [45, 47, 50], [46, 48, 51, 52], range(113, 116)],
    4 => [[53], [54, 56], [55, 57], [117, 118]],
];
// A young x1 server: most characters are low, few have reached the third profession.
$levelFor = static function (): array {
    $roll = mt_rand(1, 100);

    return match (true) {
        $roll <= 30 => [mt_rand(1, 19), 0],
        $roll <= 62 => [mt_rand(20, 39), 1],
        $roll <= 96 => [mt_rand(40, 75), 2],
        default => [mt_rand(76, 80), 3],
    };
};
$firstParts = ['Dark', 'Shadow', 'Storm', 'Iron', 'Silver', 'Night', 'Blood', 'Frost', 'Fire', 'Wild', 'Raven', 'Wolf', 'Dragon', 'Stone',
    'Light', 'Sky', 'Ghost', 'Rune', 'Ash', 'Crimson', 'Moon', 'Sun', 'Grim', 'Gold', 'Black', 'White', 'Thunder', 'Star', 'Steel', 'Mist'];
$secondParts = ['blade', 'heart', 'fang', 'wing', 'born', 'walker', 'rider', 'bane', 'claw', 'song', 'fury', 'hammer', 'shade', 'soul',
    'hunter', 'guard', 'storm', 'mage', 'lord', 'star', 'arrow', 'shield', 'flame', 'thorn', 'veil', 'whisper', 'reaver', 'spark'];
$nicknames = ['Kerberos', 'Valkyrie', 'Moonlight', 'Nightmare', 'Morgana', 'Lilith', 'Ragnar', 'Kassandra', 'Freya', 'Thorin', 'Elendil',
    'Arwen', 'Zephyr', 'Nyx', 'Hecate', 'Fenrir', 'Sigurd', 'Brunhild', 'Azazel', 'Seraphim', 'Mordred', 'Gawain', 'Isolde', 'Lucian',
    'Selene', 'Orion', 'Draven', 'Vesper', 'Kaida', 'Ezra', 'Riven', 'Solace', 'Eclipse', 'Tempest', 'Wraith', 'Aurora', 'Nemesis'];
$used = [];
$name = static function () use ($firstParts, $secondParts, $nicknames, &$used): string {
    do {
        $base = mt_rand(0, 3) === 0
            ? $nicknames[array_rand($nicknames)]
            : $firstParts[array_rand($firstParts)].$secondParts[array_rand($secondParts)];
        $candidate = isset($used[$base]) ? substr($base, 0, 13).mt_rand(1, 999) : $base;
    } while (isset($used[$candidate]));
    $used[$candidate] = true;

    return $candidate;
};
$clanNames = ['Legion', 'Phoenix', 'Immortals', 'DarkSide', 'Valhalla', 'Vendetta', 'Templars', 'Exodus', 'Avalon', 'Revenant',
    'NightWatch', 'Insomnia', 'Imperium', 'Requiem', 'Paladins', 'BloodMoon', 'Ascension', 'Brotherhood', 'Eternity', 'Nemesis',
    'Spartans', 'Wolfpack', 'Crusaders', 'Dominion', 'Inquisition', 'Mercenaries', 'Outlaws', 'Rebellion', 'Sentinels', 'Titans',
    'Vanguard', 'Warlords', 'Zealots', 'Apocalypse', 'Bastion', 'Covenant', 'Dynasty', 'Empire', 'Fearless', 'Guardians',
    'Harbingers', 'Hellfire', 'Kingdom', 'Leviathan', 'Monarchy', 'Nightfall', 'Oblivion', 'Pantheon', 'Ravenloft', 'Sanctuary',
    'Tribunal', 'Underworld', 'Vikings', 'Wardens', 'Xenon', 'Yggdrasil', 'Zenith', 'Avengers', 'Berserkers', 'Chimera'];
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
        [$level, $tier] = $levelFor();
        $classId = $classes[$race][$tier][array_rand($classes[$race][$tier])];
        $characterId++;
        $characterRows[] = [
            'account_name' => $login,
            'charId' => $characterId,
            'char_name' => $name(),
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
            'pvpkills' => $level >= 40 && mt_rand(0, 2) === 0 ? mt_rand(0, $level * 12) : 0,
            'pkkills' => $level >= 30 && mt_rand(0, 12) === 0 ? mt_rand(0, $level * 2) : 0,
            'clanid' => $level >= 20 && mt_rand(0, 9) < 5 ? 1_000 + mt_rand(1, 60) : 0,
            'race' => $race,
            'classid' => $classId,
            'base_class' => $classId,
            'deletetime' => 0,
            'title' => '',
            'accesslevel' => 0,
            'online' => mt_rand(0, 19) === 0 ? 1 : 0,
            'onlinetime' => $level * mt_rand(9_000, 30_000),
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
        'clan_name' => $clanNames[$clan - 1],
        'clan_level' => mt_rand(1, 8),
        'reputation_score' => mt_rand(0, 50_000),
        'hasCastle' => $clan <= count($castles) ? $clan : 0,
        'leader_id' => 0,
    ];
}
// The strongest member leads each clan.
foreach ($characterRows as $row) {
    $index = $row['clanid'] - 1_001;
    if ($index >= 0 && ($clanRows[$index]['leader_level'] ?? 0) < $row['level']) {
        $clanRows[$index]['leader_id'] = $row['charId'];
        $clanRows[$index]['leader_level'] = $row['level'];
    }
}
$clanRows = array_map(static function (array $clan): array {
    unset($clan['leader_level']);

    return $clan;
}, $clanRows);
$insert($pdo, 'clan_data', $clanRows);
$insert($pdo, 'castle', array_map(
    static fn (int $index): array => ['id' => $index + 1, 'name' => $castles[$index], 'taxPercent' => 10, 'treasury' => mt_rand(0, 900_000_000)],
    array_keys($castles),
));

// One hero per third-profession class, as the Olympiad crowns them.
$heroes = [];
foreach ($characterRows as $row) {
    if ($row['classid'] >= 88 && ! isset($heroes[$row['classid']])) {
        $heroes[$row['classid']] = ['charId' => $row['charId'], 'class_id' => $row['classid'], 'count' => 1, 'played' => 1, 'claimed' => 'true', 'message' => ''];
    }
}
$heroes = array_values($heroes);
$insert($pdo, 'heroes', $heroes);

printf("accounts %d, characters %d, online %d, clans %d, castles %d, heroes %d\n",
    count($accountRows), count($characterRows), count(array_filter($characterRows, static fn (array $row): bool => $row['online'] === 1)),
    count($clanRows), count($castles), count($heroes));
