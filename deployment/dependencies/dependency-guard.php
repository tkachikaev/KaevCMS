<?php

declare(strict_types=1);

/*
 * STOP! DEPENDENCY CHANGES REQUIRE EXPLICIT OWNER APPROVAL.
 * СТОП! Composer-зависимости меняются только после явного согласования с владельцем KaevCMS.
 *
 * composer.json and composer.lock are pinned to the fingerprint recorded in
 * approved-dependencies.json. Release builds and CI run this guard and fail when
 * either file differs. Installing the approved composer.lock is never affected.
 *
 *   php deployment/dependencies/dependency-guard.php            check (exit 1 if unapproved)
 *   php deployment/dependencies/dependency-guard.php --approve  owner approval, interactive only
 */

const KAEV_DEPENDENCY_APPROVAL_FILE = 'deployment/dependencies/approved-dependencies.json';

/** Packages shown to the owner when an approval is requested. */
const KAEV_DEPENDENCY_KEY_PACKAGES = ['laravel/framework', 'livewire/livewire'];

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(kaevDependencyGuardMain(getopt('', ['root::', 'approve', 'reason::', 'by::'])));
}

/**
 * @param  array<string, mixed>|false  $options
 */
function kaevDependencyGuardMain(array|false $options): int
{
    $options = is_array($options) ? $options : [];
    $root = realpath(trim((string) ($options['root'] ?? dirname(__DIR__, 2))));

    try {
        if (! is_string($root) || ! is_dir($root)) {
            throw new RuntimeException('--root must point to the KaevCMS source tree.');
        }

        if (array_key_exists('approve', $options)) {
            return kaevDependencyApproveInteractively(
                $root,
                trim((string) ($options['reason'] ?? '')),
                trim((string) ($options['by'] ?? 'KaevCMS owner')),
            );
        }

        kaevDependencyAssertApproved($root);
        fwrite(STDOUT, "Composer dependencies match the owner-approved composer.json and composer.lock.\n");

        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");

        return 1;
    }
}

/**
 * SHA-256 of composer.json and composer.lock. Line endings are normalised so a
 * checkout converted to CRLF is not mistaken for a dependency change.
 *
 * @return array{composer_json_sha256: string, composer_lock_sha256: string}
 */
function kaevDependencyFingerprint(string $root): array
{
    $fingerprint = [];
    foreach (['composer.json' => 'composer_json_sha256', 'composer.lock' => 'composer_lock_sha256'] as $file => $key) {
        $path = $root.DIRECTORY_SEPARATOR.$file;
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($contents)) {
            throw new RuntimeException($file.' is missing.');
        }
        $fingerprint[$key] = hash('sha256', str_replace("\r\n", "\n", $contents));
    }

    return $fingerprint;
}

/**
 * @return array{composer_json_sha256: string, composer_lock_sha256: string, approved_by: string, approved_at: string, reason: string, key_packages: array<string, string>}
 */
function kaevDependencyReadApproval(string $root): array
{
    $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, KAEV_DEPENDENCY_APPROVAL_FILE);
    $contents = is_file($path) ? file_get_contents($path) : false;
    $approval = is_string($contents) ? json_decode($contents, true) : null;
    if (! is_array($approval) || ($approval['schema'] ?? null) !== 1) {
        throw new RuntimeException(KAEV_DEPENDENCY_APPROVAL_FILE.' is missing or invalid. Dependency changes cannot be verified.');
    }

    foreach (['composer_json_sha256', 'composer_lock_sha256'] as $key) {
        if (! is_string($approval[$key] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $approval[$key]) !== 1) {
            throw new RuntimeException(KAEV_DEPENDENCY_APPROVAL_FILE.' contains an invalid '.$key.'.');
        }
    }

    $keyPackages = is_array($approval['key_packages'] ?? null) ? $approval['key_packages'] : [];

    return [
        'composer_json_sha256' => $approval['composer_json_sha256'],
        'composer_lock_sha256' => $approval['composer_lock_sha256'],
        'approved_by' => (string) ($approval['approved_by'] ?? ''),
        'approved_at' => (string) ($approval['approved_at'] ?? ''),
        'reason' => (string) ($approval['reason'] ?? ''),
        'key_packages' => array_map('strval', $keyPackages),
    ];
}

/**
 * @return list<string> the files that differ from the approved fingerprint
 */
function kaevDependencyUnapprovedFiles(string $root): array
{
    $approval = kaevDependencyReadApproval($root);
    $actual = kaevDependencyFingerprint($root);

    $changed = [];
    if (! hash_equals($approval['composer_json_sha256'], $actual['composer_json_sha256'])) {
        $changed[] = 'composer.json';
    }
    if (! hash_equals($approval['composer_lock_sha256'], $actual['composer_lock_sha256'])) {
        $changed[] = 'composer.lock';
    }

    return $changed;
}

function kaevDependencyAssertApproved(string $root): void
{
    $changed = kaevDependencyUnapprovedFiles($root);
    if ($changed === []) {
        return;
    }

    $files = implode(' and ', $changed);
    throw new RuntimeException(implode("\n", [
        'STOP! DEPENDENCY CHANGES REQUIRE EXPLICIT OWNER APPROVAL.',
        'СТОП! Composer-зависимости изменены без согласования с владельцем KaevCMS.',
        '',
        "Changed without approval / изменено без согласования: {$files}.",
        '',
        'If the owner did not agree to this change, revert it / если изменение не согласовано, откатите его:',
        '  git checkout -- composer.json composer.lock',
        '',
        'If the owner approved it, the owner records the approval in an interactive terminal /',
        'если владелец согласовал изменение, он сам фиксирует его в терминале:',
        '  php deployment/dependencies/dependency-guard.php --approve --reason="..."',
    ]));
}

/**
 * Versions of the key packages in composer.lock, for the owner's review.
 *
 * @return array<string, string>
 */
function kaevDependencyKeyPackages(string $root): array
{
    $contents = file_get_contents($root.DIRECTORY_SEPARATOR.'composer.lock');
    $lock = is_string($contents) ? json_decode($contents, true) : null;
    if (! is_array($lock)) {
        throw new RuntimeException('composer.lock is not valid JSON.');
    }

    $versions = [];
    foreach (array_merge((array) ($lock['packages'] ?? []), (array) ($lock['packages-dev'] ?? [])) as $package) {
        if (is_array($package) && in_array($package['name'] ?? null, KAEV_DEPENDENCY_KEY_PACKAGES, true)) {
            $versions[(string) $package['name']] = (string) ($package['version'] ?? '');
        }
    }
    ksort($versions);

    return $versions;
}

/**
 * The phrase the owner types to approve exactly this pair of files.
 */
function kaevDependencyConfirmationPhrase(string $root): string
{
    $fingerprint = kaevDependencyFingerprint($root);

    return 'APPROVE '.strtoupper(substr(hash('sha256', $fingerprint['composer_json_sha256'].$fingerprint['composer_lock_sha256']), 0, 8));
}

/**
 * Records the current composer.json and composer.lock as approved. Called only after
 * the owner confirmed in an interactive terminal (or by the guard's own tests).
 *
 * @return array<string, mixed>
 */
function kaevDependencyRecordApproval(string $root, string $approvedBy, string $reason, string $approvedAt): array
{
    if ($approvedBy === '' || $reason === '' || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $approvedAt) !== 1) {
        throw new RuntimeException('An approval needs the approver, a reason and a date.');
    }

    $approval = array_merge(['schema' => 1], kaevDependencyFingerprint($root), [
        'key_packages' => kaevDependencyKeyPackages($root),
        'approved_by' => $approvedBy,
        'approved_at' => $approvedAt,
        'reason' => $reason,
    ]);

    $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, KAEV_DEPENDENCY_APPROVAL_FILE);
    $json = json_encode($approval, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // Two-space indentation, like the other JSON files in the repository.
    $json = (string) preg_replace_callback('/^(?: {4})+/m', static fn (array $indent): string => str_repeat(' ', intdiv(strlen($indent[0]), 2)), $json);
    if (file_put_contents($path, $json."\n") === false) {
        throw new RuntimeException('Unable to write '.KAEV_DEPENDENCY_APPROVAL_FILE.'.');
    }

    return $approval;
}

/**
 * Approval is typed by a person: scripts, CI and automated assistants have no
 * interactive terminal and are refused.
 */
function kaevDependencyApproveInteractively(string $root, string $reason, string $approvedBy): int
{
    if (! function_exists('stream_isatty') || ! stream_isatty(STDIN) || ! stream_isatty(STDOUT)) {
        throw new RuntimeException(implode("\n", [
            'Dependency approval must be typed by the KaevCMS owner in an interactive terminal.',
            'Scripts, CI and automated assistants cannot approve dependency changes.',
            'Согласование зависимостей вводит только владелец KaevCMS в интерактивном терминале.',
        ]));
    }
    if ($reason === '') {
        throw new RuntimeException('Describe the approved change: --reason="Laravel 13.39 security fix"');
    }

    $changed = kaevDependencyUnapprovedFiles($root);
    if ($changed === []) {
        fwrite(STDOUT, "composer.json and composer.lock are already approved; nothing to do.\n");

        return 0;
    }

    $previous = kaevDependencyReadApproval($root)['key_packages'];
    $current = kaevDependencyKeyPackages($root);
    $phrase = kaevDependencyConfirmationPhrase($root);

    fwrite(STDOUT, 'Changed / изменено: '.implode(', ', $changed)."\n\n");
    foreach (array_unique(array_merge(array_keys($previous), array_keys($current))) as $package) {
        fwrite(STDOUT, sprintf("  %-20s %s -> %s\n", $package, $previous[$package] ?? '-', $current[$package] ?? '-'));
    }
    fwrite(STDOUT, "\nReason / причина: {$reason}\n");
    fwrite(STDOUT, "Release packages will carry these dependencies to every site.\n");
    fwrite(STDOUT, "Пакеты обновлений доставят эти зависимости на все сайты.\n\n");
    fwrite(STDOUT, "Type \"{$phrase}\" to approve / введите \"{$phrase}\" для согласования: ");

    $answer = trim((string) fgets(STDIN));
    if ($answer !== $phrase) {
        fwrite(STDERR, "Not approved. Nothing was changed.\n");

        return 1;
    }

    kaevDependencyRecordApproval($root, $approvedBy, $reason, gmdate('Y-m-d'));
    fwrite(STDOUT, 'Approved. Commit '.KAEV_DEPENDENCY_APPROVAL_FILE." together with composer.json and composer.lock.\n");

    return 0;
}
