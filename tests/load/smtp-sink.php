<?php

/**
 * A local SMTP server that accepts every message and delivers nothing, for mail load tests.
 * It answers many connections at once and can pretend to be a slow provider.
 *
 *   php tests/load/smtp-sink.php --port=2525 --delay-ms=200 --log=storage/load-test/smtp.log
 *
 * Each accepted message appends "unix-time-ms<TAB>recipients<TAB>bytes" to the log. No TLS and
 * no authentication: bind it to 127.0.0.1 only and point a TEST site at it.
 */
$options = getopt('', ['port::', 'delay-ms::', 'log::']);
$port = (int) ($options['port'] ?? 2525);
$delay = max(0, (int) ($options['delay-ms'] ?? 0)) / 1000;
$log = (string) ($options['log'] ?? 'smtp-sink.log');

$server = stream_socket_server('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "Cannot listen on 127.0.0.1:{$port}: {$errorMessage}\n");
    exit(1);
}
stream_set_blocking($server, false);
fwrite(STDOUT, "SMTP sink on 127.0.0.1:{$port}, reply delay ".($delay * 1000)." ms, log {$log}\n");

/** @var array<int, array{socket: resource, buffer: string, data: bool, message: string, recipients: list<string>, due: float|null, reply: string}> $clients */
$clients = [];
$accepted = 0;
$send = static function ($socket, string $line): void {
    @fwrite($socket, $line."\r\n");
};

while (true) {
    $read = [$server];
    foreach ($clients as $client) {
        $read[] = $client['socket'];
    }
    $write = null;
    $except = null;
    @stream_select($read, $write, $except, 0, 20_000);

    foreach ($read as $socket) {
        if ($socket === $server) {
            $connection = @stream_socket_accept($server, 0);
            if ($connection !== false) {
                stream_set_blocking($connection, false);
                $clients[(int) $connection] = ['socket' => $connection, 'buffer' => '', 'data' => false, 'message' => '', 'recipients' => [], 'due' => null, 'reply' => ''];
                $send($connection, '220 kaevcms-smtp-sink ready');
            }

            continue;
        }

        $id = (int) $socket;
        $chunk = @fread($socket, 65536);
        if ($chunk === '' || $chunk === false) {
            if (feof($socket)) {
                fclose($socket);
                unset($clients[$id]);
            }

            continue;
        }
        $clients[$id]['buffer'] .= $chunk;

        while (isset($clients[$id]) && $clients[$id]['due'] === null) {
            $client = &$clients[$id];
            if ($client['data']) {
                $end = strpos($client['buffer'], "\r\n.\r\n");
                if ($end === false) {
                    break;
                }
                $client['message'] = substr($client['buffer'], 0, $end);
                $client['buffer'] = substr($client['buffer'], $end + 5);
                $client['data'] = false;
                // The provider "works" on the message before confirming it.
                $client['due'] = microtime(true) + $delay;
                $client['reply'] = '250 2.0.0 queued';
                unset($client);
                break;
            }

            $newline = strpos($client['buffer'], "\r\n");
            if ($newline === false) {
                unset($client);
                break;
            }
            $line = substr($client['buffer'], 0, $newline);
            $client['buffer'] = substr($client['buffer'], $newline + 2);
            $command = strtoupper(substr($line, 0, 4));

            if ($command === 'EHLO') {
                @fwrite($socket, "250-kaevcms-smtp-sink\r\n250-8BITMIME\r\n250-AUTH PLAIN LOGIN\r\n250 SIZE 52428800\r\n");
            } elseif ($command === 'HELO') {
                $send($socket, '250 kaevcms-smtp-sink');
            } elseif ($command === 'AUTH') {
                $send($socket, '235 2.7.0 accepted');
            } elseif ($command === 'MAIL') {
                $client['recipients'] = [];
                $send($socket, '250 2.1.0 ok');
            } elseif ($command === 'RCPT') {
                $client['recipients'][] = trim(substr($line, 8), '<> ');
                $send($socket, '250 2.1.5 ok');
            } elseif ($command === 'DATA') {
                $client['data'] = true;
                $send($socket, '354 end with <CRLF>.<CRLF>');
            } elseif ($command === 'QUIT') {
                $send($socket, '221 2.0.0 bye');
                fclose($socket);
                unset($client, $clients[$id]);
                break;
            } else {
                $send($socket, '250 2.0.0 ok'); // RSET, NOOP and the rest
            }
            unset($client);
        }
    }

    $now = microtime(true);
    foreach ($clients as $id => $client) {
        if ($client['due'] !== null && $client['due'] <= $now) {
            $accepted++;
            file_put_contents($log, sprintf("%d\t%s\t%d\n", (int) round($now * 1000), implode(',', $client['recipients']), strlen($client['message'])), FILE_APPEND);
            $send($client['socket'], $client['reply']);
            $clients[$id]['due'] = null;
            $clients[$id]['message'] = '';
        }
    }
}
