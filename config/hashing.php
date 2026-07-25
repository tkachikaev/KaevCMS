<?php

$requestedDriver = strtolower((string) env('HASH_DRIVER', 'auto'));
$availableAlgorithms = function_exists('password_algos') ? password_algos() : ['2y'];
$driver = $requestedDriver === 'auto'
    ? (in_array('argon2id', $availableAlgorithms, true) ? 'argon2id' : 'bcrypt')
    : $requestedDriver;
$verifyAlgorithms = filter_var(env('HASH_VERIFY', true), FILTER_VALIDATE_BOOL);

return [
    'driver' => $driver,
    'requested_driver' => $requestedDriver,

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => $verifyAlgorithms,
        'limit' => null,
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 2),
        'time' => env('ARGON_TIME', 4),
        'verify' => $verifyAlgorithms,
    ],

    'rehash_on_login' => true,
];
