<?php

declare(strict_types=1);

/*
 * A burst of player registrations through the real sign-up form of a TEST site.
 *
 *   php tests/load/register-burst.php --base=http://127.0.0.1:8010 --count=200 --concurrency=50
 *
 * Every registration is a separate browser: it opens /register for its own session and CSRF
 * token, then submits a unique name and e-mail (burst<run>x<n>@load.test). Each signs up with
 * a random password that is not kept. Only a test site with test mail settings: every
 * registration asks the site to send a verification e-mail.
 */

$options = getopt('', ['base:', 'count::', 'concurrency::', 'insecure']);
$base = rtrim((string) ($options['base'] ?? ''), '/');
if (preg_match('#^https?://[^/]+$#', $base) !== 1) {
    fwrite(STDERR, "--base must be a site URL such as http://127.0.0.1:8010\n");
    exit(1);
}
$count = max(1, min(5000, (int) ($options['count'] ?? 100)));
$concurrency = max(1, min(500, (int) ($options['concurrency'] ?? 20)));
$insecure = array_key_exists('insecure', $options);
$run = base_convert((string) time(), 10, 36);

$multi = curl_multi_init();
$active = [];
$results = [];
$next = 0;
$started = microtime(true);

$open = static function (int $index, string $step, array $cookies, ?array $form = null) use ($multi, $base, $insecure, &$active): void {
    $handle = curl_init($base.'/register');
    $state = ['index' => $index, 'step' => $step, 'cookies' => $cookies, 'location' => '', 'started' => microtime(true)];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'KaevCMS-load-test',
        CURLOPT_SSL_VERIFYPEER => ! $insecure,
        CURLOPT_HTTPHEADER => $cookies === [] ? [] : ['Cookie: '.implode('; ', array_map(static fn (string $name, string $value): string => $name.'='.$value, array_keys($cookies), $cookies))],
    ]);
    if ($form !== null) {
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    }
    $id = spl_object_id($handle);
    curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$active, $id): int {
        if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $matches) === 1) {
            $active[$id]['cookies'][$matches[1]] = $matches[2];
        }
        if (preg_match('/^Location:\s*(\S+)/i', $line, $matches) === 1) {
            $active[$id]['location'] = $matches[1];
        }

        return strlen($line);
    });
    $active[$id] = $state + ['handle' => $handle];
    curl_multi_add_handle($multi, $handle);
};

for (; $next < min($count, $concurrency); $next++) {
    $open($next, 'form', []);
}

do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.2);
    while (($info = curl_multi_info_read($multi)) !== false) {
        $handle = $info['handle'];
        $state = $active[spl_object_id($handle)];
        unset($active[spl_object_id($handle)]);
        curl_multi_remove_handle($multi, $handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $body = (string) curl_multi_getcontent($handle);
        $index = $state['index'];

        if ($state['step'] === 'form') {
            if ($status === 200 && preg_match('/name="_token"\s+value="([^"]+)"/', $body, $matches) === 1) {
                $open($index, 'submit', $state['cookies'], [
                    '_token' => html_entity_decode($matches[1]),
                    'name' => sprintf('burst%sx%d', $run, $index),
                    'email' => sprintf('burst%sx%d@load.test', $run, $index),
                    'password' => $password = 'Lt'.bin2hex(random_bytes(6)).'9',
                    'password_confirmation' => $password,
                ]);
            } else {
                $results[$index] = ['ok' => false, 'reason' => 'form HTTP '.$status, 'ms' => 0];
            }

            continue;
        }

        $verified = $status === 302 && str_contains($state['location'], '/email/verify');
        $results[$index] = [
            'ok' => $verified,
            'reason' => $verified ? 'ok' : ($status === 302 ? 'redirect '.parse_url($state['location'], PHP_URL_PATH) : 'HTTP '.$status),
            'ms' => (int) round((microtime(true) - $state['started']) * 1000),
        ];
        if ($next < $count) {
            $open($next++, 'form', []);
        }
    }
} while ($active !== [] || $running > 0);
curl_multi_close($multi);

$elapsed = microtime(true) - $started;
$times = array_column(array_filter($results, static fn (array $result): bool => $result['ok']), 'ms');
sort($times);
$at = static fn (float $p): int => $times === [] ? 0 : $times[(int) min(count($times) - 1, floor($p * count($times)))];
$reasons = array_count_values(array_column($results, 'reason'));
arsort($reasons);

printf("run %s: %d registrations, %d at once, %.1f s, %.1f per second\n", $run, $count, $concurrency, $elapsed, $count / max(0.001, $elapsed));
printf("sign-up request p50 %d ms, p95 %d ms, max %d ms\n", $at(.5), $at(.95), $times === [] ? 0 : end($times));
foreach ($reasons as $reason => $total) {
    printf("  %-30s %d\n", $reason, $total);
}
