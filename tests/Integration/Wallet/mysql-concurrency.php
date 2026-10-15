<?php

/**
 * Optional real MySQL/MariaDB concurrency gate for the Wallet engine.
 * Run only on a disposable test database:
 * APP_ENV=testing WALLET_TEST_ALLOW_CONCURRENCY=1 php tests/Integration/Wallet/mysql-concurrency.php
 *
 * Unlike SQLite in-memory PHPUnit tests, this starts multiple PHP processes
 * using independent database connections with overlapping transactions.
 */

use App\Exceptions\WalletOperationException;
use App\Models\User;
use App\Models\Admin;
use App\Auth\AdminRole;
use App\Services\Wallet\WalletStatusService;
use App\Services\Wallet\WalletLedgerReconciler;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) DB::connection()->getDatabaseName();
if ((string) config('app.env') !== 'testing'
    || DB::connection()->getDriverName() !== 'mysql'
    || preg_match('/(?:test|testing)/i', $database) !== 1
    || getenv('WALLET_TEST_ALLOW_CONCURRENCY') !== '1') {
    fwrite(STDERR, "Refusing to run: requires APP_ENV=testing, MySQL/MariaDB test database, and WALLET_TEST_ALLOW_CONCURRENCY=1.\n");
    exit(2);
}

$args = [];
foreach (array_slice($argv, 1) as $argument) {
    $pieces = explode('=', ltrim($argument, '-'), 2);
    $args[$pieces[0]] = $pieces[1] ?? true;
}
$engine = $app->make(WalletTransactionService::class);
if (isset($args['worker'])) {
    $user = User::query()->findOrFail((int) ($args['user'] ?? 0));
    $start = (float) ($args['start'] ?? 0);
    while (microtime(true) < $start) {
        usleep(2000);
    }
    try {
        if ($args['mode'] === 'block') {
            $actor = Admin::query()->findOrFail((int) ($args['admin'] ?? 0));
            $app->make(WalletStatusService::class)->change(
                $actor, $user, true, (string) $args['key'], 'Concurrent-spending safety test',
            );
        } elseif ($args['mode'] === 'credit') {
            $engine->credit($user, (int) $args['amount'], 'wallet-race', (string) $args['key']);
        } else {
            $engine->debit($user, 1, 'wallet-race', (string) $args['key']);
        }
        echo "ok\n";
        exit(0);
    } catch (WalletOperationException $exception) {
        if ($exception->reason === 'insufficient_funds') {
            echo "insufficient_funds\n";
            exit(12);
        }
        if ($exception->reason === 'wallet_blocked') {
            echo "wallet_blocked\n";
            exit(15);
        }
        fwrite(STDERR, "Wallet rejected with {$exception->reason}\n");
        exit(13);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Unexpected '.get_class($exception)."\n");
        exit(14);
    }
}

if (! function_exists('proc_open')) {
    fwrite(STDERR, "proc_open is unavailable; real concurrent workers cannot be started.\n");
    exit(2);
}

/** @return array<int,int> */
function runWorkers(int $userId, string $mode, string $keyPrefix, int $count, int $amount): array
{
    $workers = [];
    $start = microtime(true) + 1.5;
    for ($i = 0; $i < $count; $i++) {
        $key = $mode === 'credit' ? $keyPrefix : $keyPrefix.'-'.$i;
        $command = [PHP_BINARY, __FILE__, '--worker', '--user='.$userId, '--mode='.$mode, '--amount='.$amount, '--key='.$key, '--start='.$start];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to create concurrent PHP worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $results[] = $exit;
        if ($exit !== 0 && $exit !== 12) {
            fwrite(STDERR, 'Worker failed: '.trim((string) $output).' '.trim((string) $errors)."\n");
        }
    }

    return $results;
}

// Creates only isolated test users in the explicitly selected disposable DB.
$id = (string) Str::uuid();
$debitUser = User::factory()->create(['email' => 'wallet-race-debit-'.substr($id, 0, 8).'@example.test']);
$engine->credit($debitUser, 5, 'wallet-race', 'initial-'.$id);
$debitResults = runWorkers((int) $debitUser->id, 'debit', 'debit-'.$id, 10, 1);
$debitBalance = (int) $debitUser->fresh()->wallet->balance;
$debitEntries = (int) DB::table('wallet_entries')->where('wallet_id', $debitUser->fresh()->wallet->id)->count();
if (count(array_filter($debitResults, fn (int $code): bool => $code === 0)) !== 5
    || count(array_filter($debitResults, fn (int $code): bool => $code === 12)) !== 5
    || $debitBalance !== 0 || $debitEntries !== 6) {
    throw new RuntimeException('Concurrent debits violated balance/ledger invariants.');
}

$creditUser = User::factory()->create(['email' => 'wallet-race-credit-'.substr($id, 0, 8).'@example.test']);
$creditResults = runWorkers((int) $creditUser->id, 'credit', 'paid-'.$id, 10, 100);
$creditWallet = $creditUser->fresh()->wallet;
if (count(array_filter($creditResults, fn (int $code): bool => $code === 0)) !== 10
    || $creditWallet === null || $creditWallet->balance !== 100
    || DB::table('wallet_entries')->where('wallet_id', $creditWallet->id)->count() !== 1) {
    throw new RuntimeException('Concurrent identical credits violated idempotency.');
}

// A7: one status block races against ten debits. Ordering is nondeterministic,
// so assert serializable results rather than prescribing which worker wins.
$blockUser = User::factory()->create(['email' => 'wallet-race-block-'.substr($id, 0, 8).'@example.test']);
$blockAdmin = Admin::factory()->create(['role' => AdminRole::Owner]);
$engine->credit($blockUser, 5, 'wallet-race', 'initial-block-'.$id);
$start = microtime(true) + 1.5;
$workers = [];
for ($i = 0; $i < 11; $i++) {
    $mode = $i === 0 ? 'block' : 'debit';
    $command = [PHP_BINARY, __FILE__, '--worker', '--user='.$blockUser->id,
        '--admin='.$blockAdmin->id, '--mode='.$mode, '--amount=1',
        '--key=block-race-'.$id.'-'.$i, '--start='.$start];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start status/debit worker.');
    }
    fclose($pipes[0]);
    $workers[] = [$process, $pipes, $mode];
}
$blockCount = 0;
$successfulDebits = 0;
foreach ($workers as [$process, $pipes, $mode]) {
    $output = trim((string) stream_get_contents($pipes[1]));
    $error = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($mode === 'block' && $exit === 0) {
        $blockCount++;
    } elseif ($mode === 'debit' && $exit === 0) {
        $successfulDebits++;
    } elseif ($mode !== 'debit' || ! in_array($exit, [12, 15], true)) {
        throw new RuntimeException('Unexpected wallet status/debit worker result: '.$mode.' / '.$exit.' / '.$output.' / '.$error);
    }
}
$wallet = $blockUser->fresh()->wallet;
if ($blockCount !== 1 || $wallet === null || ! $wallet->is_blocked
    || $wallet->balance !== 5 - $successfulDebits
    || DB::table('wallet_entries')->where('wallet_id', $wallet->id)->count() !== 1 + $successfulDebits
    || DB::table('wallet_status_events')->where('wallet_id', $wallet->id)->count() !== 1
    || ! $app->make(WalletLedgerReconciler::class)->check((int) $wallet->id)->valid) {
    throw new RuntimeException('Concurrent wallet block/debit violated ordering or ledger invariants.');
}
try {
    $engine->debit($blockUser, 1, 'wallet-race', 'post-block-'.$id);
    throw new RuntimeException('Blocked wallet permitted a subsequent debit.');
} catch (WalletOperationException $exception) {
    if ($exception->reason !== 'wallet_blocked') {
        throw $exception;
    }
}

echo "PASS: 10 concurrent debits (5 accepted, 5 insufficient); 10 identical credits (one ledger entry); concurrent block/debit serializable and ledger consistent.\n";
