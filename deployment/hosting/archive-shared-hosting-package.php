<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This archive tool may only be run from PHP CLI.\n");
    exit(1);
}

if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(package|zip)=(.+)$/', $argument, $matches) !== 1) {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(1);
    }
    $options[$matches[1]] = $matches[2];
}

$packageDirectory = isset($options['package']) ? rtrim(str_replace('\\', '/', $options['package']), '/') : '';
$zipPath = isset($options['zip']) ? str_replace('\\', '/', $options['zip']) : '';
if ($packageDirectory === '' || ! is_dir($packageDirectory)) {
    fwrite(STDERR, "Prepared package directory is missing.\n");
    exit(1);
}
if ($zipPath === '' || preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $zipPath) !== 1) {
    fwrite(STDERR, "An absolute --zip path is required.\n");
    exit(1);
}

$builder = __DIR__.'/build-shared-hosting-package.php';
define('KAEVCMS_PACKAGE_BUILDER_FUNCTIONS_ONLY', true);
require $builder;

@unlink($zipPath);
createPackageZip($packageDirectory, $zipPath);
$checksum = hash_file('sha256', $zipPath);
if (! is_string($checksum)) {
    fwrite(STDERR, "Unable to calculate archive SHA256.\n");
    exit(1);
}
file_put_contents($zipPath.'.sha256', $checksum.'  '.basename($zipPath)."\n", LOCK_EX);

fwrite(STDOUT, "Archive: {$zipPath}\n");
