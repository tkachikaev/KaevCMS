<?php

declare(strict_types=1);

$builder = file_get_contents(__DIR__.'/build-package.php');
if (! is_string($builder)) {
    throw new RuntimeException('Unable to read the web update package builder.');
}

foreach ([
    'kaevcms-update.json',
    '\'payload/\'.$logicalTarget',
    '\'public/\'.substr($relative, 7)',
    '\'core/\'.$relative',
    '\'public/uploads/\'',
    "'.env.example'",
    "'.phpunit.result.cache'",
    "'public/uploads/.gitignore'",
    "'public/uploads/.htaccess'",
    "'playwright-report/'",
    "'test-results/'",
    '\'storage/\'',
    '\'vendor/\'',
    'version_compare($minimum, $maximum',
    '\'bootstrap/kaevcms-public-path.php\'',
    '\'public/kaevcms-path.php\'',
    '\'sha256\' => $hash',
    'previous-root',
    'cumulativeDeletions',
    'readDeletionHistory',
    'filterActiveDeletions',
    'legacyWebUpdaterAcceptsTarget',
    'oldest supported Web Updater',
    'New deletions detected',
] as $required) {
    if (! str_contains($builder, $required)) {
        throw new RuntimeException("Web update package builder is missing: {$required}");
    }
}

$deletionHistoryPath = __DIR__.'/deletions.json';
$deletionHistory = json_decode((string) file_get_contents($deletionHistoryPath), true);
if (! is_array($deletionHistory)
    || ($deletionHistory['0.32.1'] ?? null) !== ['core/deployment/windows/apply-0.32.0.ps1']
    || ($deletionHistory['0.32.2'] ?? null) !== ['core/deployment/windows/apply-0.32.1.ps1']
    || ($deletionHistory['0.32.3'] ?? null) !== ['core/deployment/windows/apply-0.32.2.ps1']
    || ($deletionHistory['0.32.4'] ?? null) !== ['core/deployment/windows/apply-0.32.3.ps1']
    || ($deletionHistory['0.32.5'] ?? null) !== ['core/deployment/windows/apply-0.32.4.ps1']
    || ($deletionHistory['0.32.6'] ?? null) !== ['core/deployment/windows/apply-0.32.5.ps1']
    || ($deletionHistory['0.32.7'] ?? null) !== ['core/deployment/windows/apply-0.32.6.ps1']
    || ($deletionHistory['0.32.8'] ?? null) !== ['core/deployment/windows/apply-0.32.7.ps1']
    || ($deletionHistory['0.32.9'] ?? null) !== ['core/deployment/windows/apply-0.32.8.ps1']
    || ($deletionHistory['0.32.10'] ?? null) !== ['core/deployment/windows/apply-0.32.9.ps1']
    || ($deletionHistory['0.32.11'] ?? null) !== ['core/deployment/windows/apply-0.32.10.ps1']
    || ($deletionHistory['0.32.12'] ?? null) !== ['core/deployment/windows/apply-0.32.11.ps1']
    || ($deletionHistory['0.32.13'] ?? null) !== ['core/deployment/windows/apply-0.32.12.ps1']
    || ($deletionHistory['0.32.14'] ?? null) !== ['core/deployment/windows/apply-0.32.13.ps1']
    || ($deletionHistory['0.32.15'] ?? null) !== ['core/deployment/windows/apply-0.32.14.ps1']
    || ($deletionHistory['0.32.16'] ?? null) !== ['core/deployment/windows/apply-0.32.15.ps1']
    || ($deletionHistory['0.32.17'] ?? null) !== ['core/deployment/windows/apply-0.32.16.ps1']
    || ($deletionHistory['0.32.18'] ?? null) !== ['core/deployment/windows/apply-0.32.17.ps1']
    || ($deletionHistory['0.32.19'] ?? null) !== ['core/deployment/windows/apply-0.32.18.ps1']
    || ($deletionHistory['0.32.20'] ?? null) !== ['core/deployment/windows/apply-0.32.19.ps1']
    || ($deletionHistory['0.33.0'] ?? null) !== ['core/deployment/windows/apply-0.32.20.ps1']
    || ($deletionHistory['0.33.1'] ?? null) !== ['core/deployment/windows/apply-0.33.0.ps1']
    || ($deletionHistory['0.33.2'] ?? null) !== ['core/deployment/windows/apply-0.33.1.ps1']
    || ($deletionHistory['0.33.3'] ?? null) !== ['core/deployment/windows/apply-0.33.2.ps1']
    || ($deletionHistory['0.33.4'] ?? null) !== ['core/deployment/windows/apply-0.33.3.ps1']
    || ($deletionHistory['0.33.5'] ?? null) !== ['core/deployment/windows/apply-0.33.4.ps1']
    || ($deletionHistory['0.33.6'] ?? null) !== ['core/deployment/windows/apply-0.33.5.ps1']
    || ($deletionHistory['0.33.7'] ?? null) !== [
        'core/app/Console/Commands/ImportInterludeItemsCommand.php',
        'core/app/Services/GameAssets/Import/InterludeItemImporter.php',
        'core/deployment/windows/apply-0.33.6.ps1',
        'core/tests/Unit/InterludeItemImporterTest.php',
    ]
    || ($deletionHistory['0.33.8'] ?? null) !== [
        'core/app/Console/Commands/ImportGameItemsCommand.php',
        'core/app/Services/GameAssets/Import',
        'core/deployment/windows/apply-0.33.7.ps1',
    ]
    || ($deletionHistory['0.33.9'] ?? null) !== ['core/deployment/windows/apply-0.33.8.ps1']
    || ($deletionHistory['0.34.0'] ?? null) !== ['core/deployment/windows/apply-0.33.9.ps1']
    || ($deletionHistory['0.34.1'] ?? null) !== ['core/deployment/windows/apply-0.34.0.ps1']
    || ($deletionHistory['0.34.2'] ?? null) !== ['core/deployment/windows/apply-0.34.1.ps1']
    || ($deletionHistory['0.34.3'] ?? null) !== ['core/deployment/windows/apply-0.34.2.ps1']
    || ($deletionHistory['0.34.4'] ?? null) !== ['core/deployment/windows/apply-0.34.3.ps1']
    || ($deletionHistory['0.34.5'] ?? null) !== ['core/deployment/windows/apply-0.34.4.ps1']
    || ($deletionHistory['0.34.6'] ?? null) !== ['core/deployment/windows/apply-0.34.5.ps1']
    || ($deletionHistory['0.34.7'] ?? null) !== ['core/deployment/windows/apply-0.34.6.ps1']
    || ($deletionHistory['0.34.8'] ?? null) !== ['core/deployment/windows/apply-0.34.7.ps1']
    || ($deletionHistory['0.34.9'] ?? null) !== ['core/deployment/windows/apply-0.34.8.ps1']
    || ($deletionHistory['0.35.0'] ?? null) !== ['core/deployment/windows/apply-0.34.9.ps1']
    || ($deletionHistory['0.36.0'] ?? null) !== ['core/deployment/windows/apply-0.35.0.ps1', 'public/game-assets']
    || ($deletionHistory['0.36.1'] ?? null) !== ['core/deployment/windows/apply-0.36.0.ps1']
    || ($deletionHistory['0.36.2'] ?? null) !== ['core/deployment/windows/apply-0.36.1.ps1']
    || ($deletionHistory['0.36.3'] ?? null) !== ['core/deployment/windows/apply-0.36.2.ps1']
    || ($deletionHistory['0.36.4'] ?? null) !== ['core/deployment/windows/apply-0.36.3.ps1']
    || ($deletionHistory['0.36.5'] ?? null) !== ['core/deployment/windows/apply-0.36.4.ps1']
    || ($deletionHistory['0.36.6'] ?? null) !== ['core/deployment/windows/apply-0.36.5.ps1']
    || ($deletionHistory['0.36.7'] ?? null) !== ['core/deployment/windows/apply-0.36.6.ps1']) {
    throw new RuntimeException('Web update deletion history does not include the obsolete apply scripts.');
}

$windowsBuilder = file_get_contents(dirname(__DIR__).'/windows/build-web-update-package.ps1');
if (! is_string($windowsBuilder)
    || ! str_contains($windowsBuilder, 'KaevCMS-cumulative-update-$MinimumVersion-$MaximumVersion-to-$targetVersion.zip')) {
    throw new RuntimeException('Windows update builder does not expose the supported range in the default filename.');
}

echo "Web update package builder regression checks completed successfully.\n";
