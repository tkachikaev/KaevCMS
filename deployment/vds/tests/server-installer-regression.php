<?php

declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Run in an isolated Linux container as root.\n");
    exit(77);
}
$helper = dirname(__DIR__).'/install-support.php';
$temp = sys_get_temp_dir().'/kaevcms-installer-'.bin2hex(random_bytes(8));
mkdir($temp, 0700);
$statePath = $temp.'/state.json';
$checks = 0;
$check = function (bool $ok, string $name) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($name);
    }
    $checks++;
    echo "PASS: $name\n";
};
$run = function (array $args) use ($helper): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, $helper, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out, $error];
};
try {
    [$code, $out] = $run(['state-create', $statePath, '/var/www/kaevcms', 'site.example.test', '8.5']);
    $check($code === 0 && $out === '', 'state creation does not print credentials');
    $raw = file_get_contents($statePath);
    $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $check((fileperms($statePath) & 0777) === 0600, 'state is root-only');
    $check(strlen($state['password']) === 64, 'random database password');
    $check($run(['state-get', $statePath, 'root'])[1] === '/var/www/kaevcms', 'safe metadata can be read');
    [$code, $out, $error] = $run(['state-get', $statePath, 'password']);
    $check($code !== 0 && ! str_contains($out.$error, $state['password']), 'secret fields cannot be queried as metadata');
    $check($run(['state-create', $statePath, '/var/www/kaevcms', 'site.example.test', '8.5'])[0] !== 0
        && file_get_contents($statePath) === $raw, 'rerun never replaces state or passwords');
    chmod($statePath, 0644);
    $check($run(['state-get', $statePath, 'root'])[0] !== 0, 'unsafe state permissions rejected');
    chmod($statePath, 0600);
    $state['root'] = '/etc';
    file_put_contents($statePath, json_encode($state));
    $check($run(['state-get', $statePath, 'root'])[0] !== 0, 'unexpected target paths rejected');
    file_put_contents($statePath, $raw);
    symlink($statePath, $temp.'/link.json');
    $check($run(['state-get', $temp.'/link.json', 'root'])[0] !== 0, 'state symlinks rejected');
    unlink($temp.'/link.json');
    echo "$checks installer helper checks passed.\n";
} finally {
    unlink($statePath);
    rmdir($temp);
}
