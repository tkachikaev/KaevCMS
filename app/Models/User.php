<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\Mail\MailDeliveryDispatcher;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'game_account', 'locale', 'avatar_filename'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'locale' => 'string',
            'avatar_filename' => 'string',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<UserGameAccount, $this> */
    public function gameAccounts(): HasMany
    {
        return $this->hasMany(UserGameAccount::class);
    }

    /** @return HasMany<UserGameAccount, $this> */
    public function gameAccountsCountingTowardLimit(): HasMany
    {
        return $this->gameAccounts();
    }

    /** @return HasMany<UserGameAccount, $this> */
    public function availableGameAccounts(): HasMany
    {
        return $this->gameAccounts()
            ->whereNotNull('registration_game_server_id')
            ->whereHas('registrationGameServer');
    }

    /** @return HasMany<RewardInventoryGrant, $this> */
    public function rewardInventoryGrants(): HasMany
    {
        return $this->hasMany(RewardInventoryGrant::class);
    }

    /** @return HasMany<RewardInventoryItem, $this> */
    public function rewardInventoryItems(): HasMany
    {
        return $this->hasMany(RewardInventoryItem::class);
    }

    /** @return HasMany<RewardDelivery, $this> */
    public function rewardDeliveries(): HasMany
    {
        return $this->hasMany(RewardDelivery::class);
    }

    /** @return HasOne<UserCharacterPreference, $this> */
    public function characterPreference(): HasOne
    {
        return $this->hasOne(UserCharacterPreference::class);
    }

    public function sendEmailVerificationNotification(): void
    {
        app(MailDeliveryDispatcher::class)->send($this, new VerifyEmailNotification, 'email_verification');
    }

    public function sendPasswordResetNotification($token): void
    {
        app(MailDeliveryDispatcher::class)->send($this, new ResetPasswordNotification((string) $token), 'password_reset');
    }
}
