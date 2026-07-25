<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'type',
        'recipient',
        'mode',
        'status',
        'queued_at',
        'sent_at',
        'failed_at',
        'error_class',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
