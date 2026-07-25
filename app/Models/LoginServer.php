<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string $database_host
 * @property int $database_port
 * @property string $database_name
 * @property string $database_username
 * @property string|null $database_password
 * @property string $database_charset
 * @property string|null $service_host
 * @property int|null $service_port
 * @property string $database_status
 * @property string|null $database_error
 * @property Carbon|null $database_checked_at
 * @property string $monitor_status
 * @property int $monitor_failures
 * @property Carbon|null $monitor_checked_at
 * @property Carbon|null $monitor_last_online_at
 */
class LoginServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'driver',
        'database_host',
        'database_port',
        'database_name',
        'database_username',
        'database_password',
        'database_charset',
        'service_host',
        'service_port',
        'database_status',
        'database_error',
        'database_checked_at',
        'monitor_status',
        'monitor_failures',
        'monitor_checked_at',
        'monitor_last_online_at',
    ];

    protected $hidden = [
        'database_password',
    ];

    protected function casts(): array
    {
        return [
            'database_port' => 'integer',
            'database_password' => 'encrypted',
            'service_port' => 'integer',
            'database_checked_at' => 'datetime',
            'monitor_failures' => 'integer',
            'monitor_checked_at' => 'datetime',
            'monitor_last_online_at' => 'datetime',
        ];
    }

    /** @return HasMany<GameServer, $this> */
    public function gameServers(): HasMany
    {
        return $this->hasMany(GameServer::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return HasMany<UserGameAccount, $this> */
    public function userGameAccounts(): HasMany
    {
        return $this->hasMany(UserGameAccount::class);
    }

    public function databasePassword(): ?string
    {
        try {
            return is_string($this->database_password) ? $this->database_password : null;
        } catch (DecryptException) {
            return null;
        }
    }

    public function databaseVerified(): bool
    {
        return $this->database_status === 'configured' && $this->database_checked_at !== null;
    }

    public function hasDatabasePassword(): bool
    {
        $value = $this->getRawOriginal('database_password');

        return is_string($value) && $value !== '';
    }
}
