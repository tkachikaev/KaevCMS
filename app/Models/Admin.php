<?php

namespace App\Models;

use App\Auth\AdminPermission;
use App\Auth\AdminRole;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $remember_token
 * @property bool $is_active
 * @property AdminRole $role
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int $session_version
 */
class Admin extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $attributes = [
        'role' => AdminRole::Owner->value,
        'session_version' => 1,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'role',
        'last_login_at',
        'last_login_ip',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'role' => AdminRole::class,
            'last_login_at' => 'datetime',
            'locale' => 'string',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'session_version' => 'integer',
        ];
    }

    public function hasPermission(AdminPermission|string $permission): bool
    {
        return $this->role->allows($permission);
    }

    public function isOwner(): bool
    {
        return $this->role === AdminRole::Owner;
    }

    public function roleLabel(): string
    {
        return $this->role->label();
    }

    public function roleDescription(): string
    {
        return $this->role->description();
    }

    public function twoFactorEnabled(): bool
    {
        $encryptedSecret = $this->getRawOriginal('two_factor_secret');

        return $this->two_factor_confirmed_at !== null
            && is_string($encryptedSecret)
            && $encryptedSecret !== '';
    }

    public function twoFactorSecret(): ?string
    {
        try {
            return is_string($this->two_factor_secret) ? $this->two_factor_secret : null;
        } catch (DecryptException) {
            return null;
        }
    }

    public function recoveryCodesRemaining(): ?int
    {
        $codes = $this->twoFactorRecoveryCodeHashes();

        return $codes === null ? null : count($codes);
    }

    /** @return list<string>|null */
    public function twoFactorRecoveryCodeHashes(): ?array
    {
        try {
            $codes = $this->two_factor_recovery_codes;

            return is_array($codes) ? $codes : [];
        } catch (DecryptException) {
            return null;
        }
    }
}
