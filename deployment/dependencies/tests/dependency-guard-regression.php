<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/release/build-release.php';

$projectRoot = dirname(__DIR__, 3);
$guardScript = dirname(__DIR__).'/dependency-guard.php';
$temporaryRoot = kaevReleaseTemporaryDirectory('kaevcms-dependency-guard-');

try {
    // The repository itself must be in the owner-approved state.
    kaevDependencyAssertApproved($projectRoot);

    // The warning is a top-level key: "extra" is part of composer.lock's content-hash,
    // so putting it there would make the approved lock stale.
    $composer = guardJson($projectRoot.'/composer.json');
    $warning = implode("\n", (array) ($composer['_WARNING_READ_BEFORE_CHANGING_DEPENDENCIES'] ?? []));
    guardAssert(array_key_first($composer) === '_WARNING_READ_BEFORE_CHANGING_DEPENDENCIES', 'The dependency warning must be the first key of composer.json.');
    guardAssert(str_contains($warning, 'STOP! DEPENDENCY CHANGES REQUIRE EXPLICIT OWNER APPROVAL.'), 'composer.json must carry the English dependency warning.');
    guardAssert(str_contains($warning, 'СТОП! КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО'), 'composer.json must carry the Russian dependency warning.');
    guardAssert(! isset($composer['extra']['_WARNING_READ_BEFORE_CHANGING_DEPENDENCIES']), 'The warning must not live in "extra", which changes composer.lock content-hash.');
    $lock = guardJson($projectRoot.'/composer.lock');
    guardAssert(($lock['content-hash'] ?? null) === guardComposerContentHash($composer), 'composer.lock must stay fresh for the approved composer.json.');

    // composer install of the approved lock is never blocked: no Composer hook runs the guard.
    guardAssert(! str_contains(json_encode($composer['scripts'] ?? [], JSON_THROW_ON_ERROR), 'dependency-guard'), 'Composer scripts must not run the dependency guard.');

    $fixture = $temporaryRoot.'/site';
    foreach (['composer.json', 'composer.lock', KAEV_DEPENDENCY_APPROVAL_FILE] as $file) {
        guardCopy($projectRoot.'/'.$file, $fixture.'/'.$file);
    }
    kaevDependencyAssertApproved($fixture);

    // A CRLF checkout is not a dependency change.
    foreach (['composer.json', 'composer.lock'] as $file) {
        $contents = (string) file_get_contents($fixture.'/'.$file);
        file_put_contents($fixture.'/'.$file, str_replace("\n", "\r\n", $contents));
    }
    guardAssert(kaevDependencyUnapprovedFiles($fixture) === [], 'Line-ending conversion must not count as a dependency change.');
    guardCopy($projectRoot.'/composer.json', $fixture.'/composer.json');
    guardCopy($projectRoot.'/composer.lock', $fixture.'/composer.lock');

    // An updated lock (e.g. a newer Laravel) is refused.
    $updatedLock = guardJson($fixture.'/composer.lock');
    foreach ($updatedLock['packages'] as $index => $package) {
        if (($package['name'] ?? null) === 'laravel/framework') {
            $updatedLock['packages'][$index]['version'] = 'v99.0.0';
        }
    }
    guardWriteJson($fixture.'/composer.lock', $updatedLock);
    guardAssert(kaevDependencyUnapprovedFiles($fixture) === ['composer.lock'], 'A changed composer.lock must be detected.');
    $message = guardFailure(static fn () => kaevDependencyAssertApproved($fixture));
    guardAssert(str_contains($message, 'STOP! DEPENDENCY CHANGES REQUIRE EXPLICIT OWNER APPROVAL.') && str_contains($message, 'composer.lock'), 'The refusal must name the unapproved file.');

    // The release builder refuses it before doing anything else.
    $previousArchive = $temporaryRoot.'/previous-full.zip';
    file_put_contents($previousArchive, 'not inspected');
    $message = guardFailure(static fn () => kaevReleaseBuild(['root' => $fixture, 'previous' => $previousArchive, 'output-dir' => $temporaryRoot.'/dist']));
    guardAssert(str_contains($message, 'STOP! DEPENDENCY CHANGES REQUIRE EXPLICIT OWNER APPROVAL.'), 'The release builder must refuse unapproved dependencies.');
    guardAssert(! is_dir($temporaryRoot.'/dist') || glob($temporaryRoot.'/dist/*') === [], 'No release artifact may be written for unapproved dependencies.');

    // So does the command line used by CI and the Windows build scripts.
    [$exitCode, , $stderr] = guardRun([PHP_BINARY, $guardScript, '--root='.$fixture]);
    guardAssert($exitCode === 1 && str_contains($stderr, 'composer.lock'), 'The guard CLI must exit with 1 for an unapproved lock.');

    // Approval cannot be given without an interactive terminal (CI, scripts, assistants).
    $approvalBefore = (string) file_get_contents($fixture.'/'.KAEV_DEPENDENCY_APPROVAL_FILE);
    [$exitCode, , $stderr] = guardRun([PHP_BINARY, $guardScript, '--root='.$fixture, '--approve', '--reason=test'], kaevDependencyConfirmationPhrase($fixture)."\n");
    guardAssert($exitCode === 1 && str_contains($stderr, 'interactive terminal'), 'Non-interactive approval must be refused.');
    guardAssert((string) file_get_contents($fixture.'/'.KAEV_DEPENDENCY_APPROVAL_FILE) === $approvalBefore, 'A refused approval must not touch the approval record.');

    // composer.json changes are refused too.
    guardCopy($projectRoot.'/composer.lock', $fixture.'/composer.lock');
    $changedComposer = guardJson($fixture.'/composer.json');
    $changedComposer['require']['livewire/livewire'] = '^5.0';
    guardWriteJson($fixture.'/composer.json', $changedComposer);
    guardAssert(kaevDependencyUnapprovedFiles($fixture) === ['composer.json'], 'A changed composer.json must be detected.');

    // An explicit owner approval records the new state, after which the build may proceed.
    guardWriteJson($fixture.'/composer.lock', $updatedLock);
    guardAssert(kaevDependencyConfirmationPhrase($fixture) !== kaevDependencyConfirmationPhrase($projectRoot), 'The confirmation phrase must be bound to the exact files.');
    $approval = kaevDependencyRecordApproval($fixture, 'KaevCMS owner', 'Regression fixture', '2026-01-01');
    kaevDependencyAssertApproved($fixture);
    guardAssert(($approval['key_packages']['laravel/framework'] ?? null) === 'v99.0.0', 'The approval must show the approved Laravel version.');
    [$exitCode] = guardRun([PHP_BINARY, $guardScript, '--root='.$fixture]);
    guardAssert($exitCode === 0, 'The guard CLI must pass once the change is approved.');
    guardFailure(static fn () => kaevDependencyRecordApproval($fixture, 'KaevCMS owner', '', '2026-01-01'));

    // A missing or tampered record blocks everything rather than allowing it.
    file_put_contents($fixture.'/'.KAEV_DEPENDENCY_APPROVAL_FILE, '{"schema":1,"composer_json_sha256":"x","composer_lock_sha256":"y"}');
    guardFailure(static fn () => kaevDependencyAssertApproved($fixture));
    unlink($fixture.'/'.KAEV_DEPENDENCY_APPROVAL_FILE);
    guardFailure(static fn () => kaevDependencyAssertApproved($fixture));

    fwrite(STDOUT, "Dependency approval guard regression checks passed.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Dependency approval guard regression failed: '.$exception->getMessage()."\n");
    exit(1);
} finally {
    kaevReleaseRemoveTree($temporaryRoot);
}

/**
 * Composer's own content-hash of composer.json (Composer\Package\Locker::getContentHash).
 *
 * @param  array<string, mixed>  $composer
 */
function guardComposerContentHash(array $composer): string
{
    $relevant = array_intersect_key($composer, array_flip([
        'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
        'minimum-stability', 'prefer-stable', 'repositories', 'extra',
    ]));
    if (isset($composer['config']['platform'])) {
        $relevant['config']['platform'] = $composer['config']['platform'];
    }
    ksort($relevant);

    return md5((string) json_encode($relevant));
}

/**
 * @param  list<string>  $command
 * @return array{int, string, string}
 */
function guardRun(array $command, string $input = ''): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start the dependency guard.');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

function guardFailure(callable $callback): string
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }

    throw new RuntimeException('Expected the dependency guard to refuse.');
}

/** @return array<string, mixed> */
function guardJson(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Fixture JSON root must be an object.');
    }

    return $decoded;
}

/** @param array<string, mixed> $value */
function guardWriteJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
}

function guardCopy(string $source, string $destination): void
{
    $directory = dirname($destination);
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create fixture directory.');
    }
    if (! copy($source, $destination)) {
        throw new RuntimeException('Unable to copy fixture file.');
    }
}

function guardAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
