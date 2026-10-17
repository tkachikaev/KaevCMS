<?php

// Standalone installer helper. Secrets are read from a root-only state file,
// never from command arguments, shell interpolation or installer output.
declare(strict_types=1);

function installState(string $path): array
{
    if (is_link($path) || (fileperms($path) & 0077) !== 0 || fileowner($path) !== 0) {
        throw new RuntimeException('Installer state must be owned by root with mode 0600.');
    }
    $state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($state) || ($state['schema'] ?? null) !== 1
        || ! preg_match('~\A/var/www/[a-zA-Z0-9][a-zA-Z0-9_-]*\z~D', $state['root'] ?? '')
        || ! preg_match('/\Akaevcms_[a-f0-9]{12}\z/D', $state['database'] ?? '')
        || ! preg_match('/\A[a-f0-9]{64}\z/D', $state['password'] ?? '')
        || ! validInstallHost($state['host'] ?? '')
        || ! in_array($state['php'] ?? '', ['8.3', '8.4', '8.5'], true)) {
        throw new RuntimeException('Invalid installer state.');
    }

    return $state;
}

function validInstallHost(string $host): bool
{
    return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        || (strlen($host) <= 253 && str_contains($host, '.')
            && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/D', $host) === 1);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

try {
    $action = $argv[1] ?? '';
    $path = $argv[2] ?? '';
    if ($action === 'state-create') {
        if (file_exists($path) || is_link($path) || ! validInstallHost($argv[4] ?? '')) {
            throw new RuntimeException('State already exists or site address is invalid.');
        }
        $state = [
            'schema' => 1, 'root' => $argv[3], 'host' => $argv[4], 'php' => $argv[5],
            'database' => 'kaevcms_'.bin2hex(random_bytes(6)),
            'password' => bin2hex(random_bytes(32)),
        ];
        umask(0077);
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Could not create installer state.');
        }
        fwrite($file, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        fclose($file);
        chmod($path, 0600);
        installState($path);
    } elseif ($action === 'state-get') {
        if (! in_array($argv[3] ?? '', ['root', 'host', 'php'], true)) {
            throw new RuntimeException('This state field cannot be printed.');
        }
        echo installState($path)[$argv[3]];
    } elseif ($action === 'database') {
        $state = installState($path);
        $database = $state['database'];
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            new PDO('mysql:host=127.0.0.1;dbname='.$database, $database, $state['password'], $options);
            exit(0); // Resume our own database, never replace a password or drop data.
        } catch (PDOException) {
            $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', '', $options);
            $pdo->exec('CREATE USER IF NOT EXISTS '.$pdo->quote($database)."@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($state['password']));
            // A previously interrupted CREATE USER is safe to resume only if its password works.
            new PDO('mysql:host=127.0.0.1', $database, $state['password'], $options);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('GRANT ALL PRIVILEGES ON `'.$database.'`.* TO '.$pdo->quote($database)."@'127.0.0.1'");
        }
    } elseif ($action === 'credentials') {
        $state = installState($path);
        // This action is explicitly invoked by the operator in their SSH terminal.
        echo "Host: 127.0.0.1\nPort: 3306\nDatabase: ".$state['database']."\nUsername: ".$state['database']."\nPassword: ".$state['password']."\n";
    } else {
        throw new RuntimeException('Unknown installer action.');
    }
} catch (Throwable) {
    // PDO/Laravel exception strings may contain credentials. Do not print them.
    fwrite(STDERR, 'KaevCMS: installer helper failed at '.($action ?? 'startup').". No secrets were printed. Check the installation state and database access.\n");
    exit(1);
}
