<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminLoginLog extends Model
{
    protected $fillable = [
        'admin_id',
        'email',
        'ip_address',
        'user_agent',
        'successful',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function resultLabel(): string
    {
        return $this->successful ? __('Successful') : __('Failed');
    }

    public function failureReasonLabel(): string
    {
        if ($this->successful) {
            return '—';
        }

        return match ($this->failure_reason) {
            'invalid_credentials' => __('Invalid credentials'),
            'inactive' => __('Inactive administrator'),
            'invalid_two_factor' => __('Invalid two-factor code'),
            default => __('Unknown reason'),
        };
    }
}
