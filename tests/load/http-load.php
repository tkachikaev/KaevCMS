<?php

declare(strict_types=1);

/*
 * Simple HTTP load generator for KaevCMS public pages (no third-party tools needed).
 *
 *   php tests/load/http-load.php --base=https://example.com --stages=5:30,20:30,50:30
 *
 * Options:
 *   --base=URL          site to test (required). Never point this at a production site with players.
 *   --stages=C:S,...    virtual users and seconds per stage, run one after another (default 5:20,20:20)
 *   --paths=/a,/b       pages to request (default: public pages, discovered from the site)
 *   --insecure          skip TLS verification (self-signed test servers)
 *   --players=FILE      JSON list of {"login": ..., "password": ...} TEST players; every virtual user
 *                       signs in through the login form first and stays signed in, so --paths can
 *                       be cabinet pages such as /account/characters. Use test accounts only.
 *
 * Every virtual user keeps its own cookies like a browser, so the site sees returning
 * visitors instead of a new session per request. Signed-in users sent back to the sign-in
 * page count as errors. A stage stops early when more than half
 * of its requests fail, so a struggling server is not pushed further.
 */

if (PHP_SAPI !== 'cli' || ! function_exists('curl_multi_init')) {
    fwrite(STDERR, "Run from PHP CLI with the curl extension.\n");
    exit(1);
}

$options = getopt('', ['base:', 'stages::', 'paths::', 'insecure', 'players::']);
$base = rtrim((string) ($options['base'] ?? ''), '/');
if (preg_match('#^https?://[^/]+$#', $base) !== 1) {
    fwrite(STDERR, "--base must be a site URL such as https://example.com\n");
    exit(1);
}
$insecure = array_key_exists('insecure', $options);

$stages = [];
foreach (explode(',', (string) ($options['stages'] ?? '5:20,20:20')) as $stage) {
    if (preg_match('/^(\d+):(\d+)$/', trim($stage), $matches) !== 1 || (int) $matches[1] < 1 || (int) $matches[1] > 500 || (int) $matches[2] < 1 || (int) $matches[2] > 600) {
        fwrite(STDERR, "Invalid stage '{$stage}': use USERS:SECONDS (1-500 users, 1-600 seconds).\n");
        exit(1);
    }
    $stages[] = ['users' => (int) $matches[1], 'seconds' => (int) $matches[2]];
}

$paths = isset($options['paths'])
    ? array_values(array_filter(array_map('trim', explode(',', (string) $options['paths']))))
    : discoverPaths($base, $insecure);

$players = [];
if (isset($options['players'])) {
    $players = json_decode((string) @file_get_contents((string) $options['players']), true);
    if (! is_array($players) || $players === []) {
        fwrite(STDERR, "--players must be a JSON list of {login, password}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Target: {$base}\nPages: ".implode(', ', $paths)."\n\n");
$signedIn = [];
if ($players !== []) {
    $signedIn = signIn($base, $players, max(array_column($stages, 'users')), $insecure);
    fwrite(STDOUT, 'Signed in '.count($signedIn)." virtual players.\n\n");
}
fwrite(STDOUT, str_pad('Users', 7).str_pad('Requests', 10).str_pad('Req/s', 8).str_pad('p50 ms', 9).str_pad('p95 ms', 9).str_pad('p99 ms', 9).str_pad('max ms', 9).str_pad('server p50/p95', 16)."Errors\n");

$report = [];
foreach ($stages as $stage) {
    $result = runStage($base, $paths, $stage['users'], $stage['seconds'], $insecure, $signedIn);
    $report[] = $result;
    fwrite(STDOUT, sprintf(
        "%-7d%-10d%-8.1f%-9d%-9d%-9d%-9d%-16s%d (%s)\n",
        $stage['users'],
        $result['count'],
        $result['rps'],
        $result['p50'],
        $result['p95'],
        $result['p99'],
        $result['max'],
        $result['server_p50'].'/'.$result['server_p95'],
        $result['errors'],
        $result['error_summary'] ?: 'none',
    ));
    if ($result['aborted']) {
        fwrite(STDOUT, "Stopped: more than half of the requests failed.\n");
        break;
    }
}

fwrite(STDOUT, "\nSlowest pages at the last stage (p95 ms):\n");
$last = end($report);
arsort($last['per_path']);
foreach (array_slice($last['per_path'], 0, 10, true) as $path => $p95) {
    fwrite(STDOUT, sprintf("  %-40s %d\n", $path, $p95));
}

/**
 * @return list<string>
 */
function discoverPaths(string $base, bool $insecure): array
{
    $paths = ['/', '/news', '/statistics', '/downloads', '/about', '/login', '/register'];
    foreach (['/news' => '~href="'.preg_quote($base, '~').'(/news/[^"?#]+)"~', '/statistics' => '~href="'.preg_quote($base, '~').'(/statistics/\d+)"~'] as $page => $pattern) {
        $body = fetch($base.$page, $insecure);
        if ($body !== null && preg_match($pattern, $body, $matches) === 1) {
            $paths[] = $matches[1];
        }
    }

    return $paths;
}

function fetch(string $url, bool $insecure): ?string
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'KaevCMS-load-test', CURLOPT_SSL_VERIFYPEER => ! $insecure]);
    $body = curl_exec($handle);

    return is_string($body) ? $body : null;
}

/**
 * @param  list<string>  $paths
 * @param  list<array<string,string>>  $signedIn  cookies of signed-in virtual users
 * @return array{count:int,rps:float,p50:int,server_p50:int,server_p95:int,p95:int,p99:int,max:int,errors:int,error_summary:string,aborted:bool,per_path:array<string,int>}
 */
function runStage(string $base, array $paths, int $users, int $seconds, bool $insecure, array $signedIn = []): array
{
    $multi = curl_multi_init();
    $cookies = array_slice($signedIn, 0, $users) + array_fill(0, $users, []);
    $signedInUsers = min($users, count($signedIn));
    $locations = [];
    $active = [];
    $latencies = [];
    $serverTimes = [];
    $byPath = [];
    $errors = [];
    $started = microtime(true);
    $deadline = $started + $seconds;

    $launch = static function (int $user) use (&$active, &$cookies, &$locations, $multi, $base, $paths, $insecure): void {
        $path = $paths[array_rand($paths)];
        $handle = curl_init($base.$path);
        $headers = [];
        if ($cookies[$user] !== []) {
            $headers[] = 'Cookie: '.implode('; ', array_map(static fn (string $name, string $value): string => $name.'='.$value, array_keys($cookies[$user]), $cookies[$user]));
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'KaevCMS-load-test',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => ! $insecure,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$cookies, &$locations, $user): int {
                if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $matches) === 1) {
                    $cookies[$user][$matches[1]] = $matches[2];
                }
                if (preg_match('/^Location:\s*(\S+)/i', $line, $matches) === 1) {
                    $locations[(int) spl_object_id($curl)] = $matches[1];
                }

                return strlen($line);
            },
        ]);
        curl_multi_add_handle($multi, $handle);
        $active[(int) spl_object_id($handle)] = ['handle' => $handle, 'user' => $user, 'path' => $path];
    };

    for ($user = 0; $user < $users; $user++) {
        $launch($user);
    }

    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.2);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            $key = (int) spl_object_id($handle);
            $entry = $active[$key];
            unset($active[$key]);

            $milliseconds = (int) round(curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000);
            // Server time: from the request being sent to the first byte of the answer.
            $serverTimes[] = (int) round((curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME) - curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME)) * 1000);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $signedOut = $entry['user'] < $signedInUsers && $status >= 300 && $status < 400
                && str_contains($locations[$key] ?? '', '/login');
            unset($locations[$key]);
            $failed = $info['result'] !== CURLE_OK || $status === 0 || $status >= 400 || $signedOut;
            $latencies[] = $milliseconds;
            $byPath[$entry['path']][] = $milliseconds;
            if ($failed) {
                $reason = $info['result'] !== CURLE_OK ? 'curl '.$info['result'] : ($signedOut ? 'signed out' : 'HTTP '.$status);
                $errors[$reason] = ($errors[$reason] ?? 0) + 1;
            }

            curl_multi_remove_handle($multi, $handle);
            if (microtime(true) < $deadline) {
                $launch($entry['user']);
            }
        }

        $total = count($latencies);
        $aborted = $total >= 20 && array_sum($errors) > $total / 2;
    } while (($active !== [] || $running > 0) && ! $aborted);

    foreach ($active as $entry) {
        curl_multi_remove_handle($multi, $entry['handle']);
    }
    curl_multi_close($multi);

    $elapsed = max(0.001, microtime(true) - $started);
    sort($latencies);
    sort($serverTimes);
    $percentile = static function (array $sorted, float $p): int {
        return $sorted === [] ? 0 : (int) $sorted[(int) min(count($sorted) - 1, floor($p * count($sorted)))];
    };
    $perPath = [];
    foreach ($byPath as $path => $values) {
        sort($values);
        $perPath[$path] = $percentile($values, .95);
    }
    arsort($errors);

    return [
        'count' => count($latencies),
        'rps' => count($latencies) / $elapsed,
        'p50' => $percentile($latencies, .5),
        'server_p50' => $percentile($serverTimes, .5),
        'server_p95' => $percentile($serverTimes, .95),
        'p95' => $percentile($latencies, .95),
        'p99' => $percentile($latencies, .99),
        'max' => $latencies === [] ? 0 : (int) end($latencies),
        'errors' => array_sum($errors),
        'error_summary' => implode(', ', array_map(static fn (string $reason, int $count): string => "{$reason}×{$count}", array_keys($errors), $errors)),
        'aborted' => $aborted,
        'per_path' => $perPath,
    ];
}

/**
 * Signs every virtual user in through the public login form and returns their cookies.
 *
 * @param  list<array{login:string,password:string}>  $players
 * @return list<array<string,string>>
 */
function signIn(string $base, array $players, int $users, bool $insecure): array
{
    $cookies = [];
    for ($user = 0; $user < $users; $user++) {
        $player = $players[$user % count($players)];
        $jar = [];
        $request = static function (string $url, ?array $form) use (&$jar, $insecure): array {
            $location = '';
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'KaevCMS-load-test',
                CURLOPT_SSL_VERIFYPEER => ! $insecure,
                CURLOPT_HTTPHEADER => $jar === [] ? [] : ['Cookie: '.implode('; ', array_map(static fn (string $name, string $value): string => $name.'='.$value, array_keys($jar), $jar))],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$jar, &$location): int {
                    if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $matches) === 1) {
                        $jar[$matches[1]] = $matches[2];
                    }
                    if (preg_match('/^Location:\s*(\S+)/i', $line, $matches) === 1) {
                        $location = $matches[1];
                    }

                    return strlen($line);
                },
            ]);
            if ($form !== null) {
                curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
            }
            $body = (string) curl_exec($handle);

            return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, $location];
        };

        [, $page] = $request($base.'/login', null);
        if (preg_match('/name="_token"\s+value="([^"]+)"/', $page, $matches) !== 1) {
            fwrite(STDERR, "The login page has no CSRF token.\n");
            exit(1);
        }
        [$status, , $location] = $request($base.'/login', [
            '_token' => html_entity_decode($matches[1]),
            'login' => $player['login'],
            'password' => $player['password'],
        ]);
        if ($status !== 302 || str_contains($location, '/login')) {
            fwrite(STDERR, "Sign-in failed for virtual user {$user} (HTTP {$status}).\n");
            exit(1);
        }
        $cookies[] = $jar;
    }

    return $cookies;
}