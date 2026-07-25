<?php

namespace App\Auth\Passwords;

use Illuminate\Auth\Passwords\CacheTokenRepository;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;

final class UtcPasswordBrokerManager extends PasswordBrokerManager
{
    /** @param array<string, mixed> $config */
    protected function createTokenRepository(array $config): TokenRepositoryInterface
    {
        $key = (string) $this->app['config']['app.key'];

        if (str_starts_with($key, 'base64:')) {
            $decodedKey = base64_decode(substr($key, 7), true);
            $key = is_string($decodedKey) ? $decodedKey : $key;
        }

        if (($config['driver'] ?? null) === 'cache') {
            return new CacheTokenRepository(
                $this->app['cache']->store($config['store'] ?? null),
                $this->app['hash'],
                $key,
                (int) ($config['expire'] ?? 60) * 60,
                (int) ($config['throttle'] ?? 0),
            );
        }

        return new UtcDatabaseTokenRepository(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
            (string) $config['table'],
            $key,
            (int) ($config['expire'] ?? 60) * 60,
            (int) ($config['throttle'] ?? 0),
        );
    }
}
