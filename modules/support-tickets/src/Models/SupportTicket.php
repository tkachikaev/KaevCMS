<?php

namespace KaevCMS\Modules\SupportTickets\Models;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketStatus;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $user_name_snapshot
 * @property string $user_email_snapshot
 * @property SupportTicketCategory $category
 * @property SupportTicketStatus $status
 * @property string $subject
 * @property int|null $assigned_admin_id
 * @property int|null $closed_by_admin_id
 * @property int|null $closed_by_user_id
 * @property Carbon $last_message_at
 * @property Carbon|null $last_player_message_at
 * @property Carbon|null $last_staff_message_at
 * @property Carbon|null $closed_at
 * @property bool $retention_protected
 * @property-read User|null $user
 * @property-read Admin|null $assignedAdmin
 * @property-read Collection<int, SupportTicketMessage> $messages
 */
final class SupportTicket extends Model
{
    public const SUBJECT_MAX = 120;

    public const INITIAL_MESSAGE_MAX = 3000;

    public const MESSAGE_MAX = 2000;

    public const MAX_OPEN_TICKETS_PER_USER = 5;

    public const MAX_TICKETS_PER_USER_PER_DAY = 10;

    public const MAX_PLAYER_MESSAGES_PER_DAY = 100;

    public const MAX_MESSAGES_PER_TICKET = 300;

    public const MAX_REVISIONS_PER_MESSAGE = 20;

    public const MESSAGES_PER_PAGE = 50;

    protected $table = 'module_support_tickets';

    protected $fillable = [
        'user_id',
        'user_name_snapshot',
        'user_email_snapshot',
        'category',
        'status',
        'subject',
        'assigned_admin_id',
        'closed_by_admin_id',
        'closed_by_user_id',
        'last_message_at',
        'last_player_message_at',
        'last_staff_message_at',
        'closed_at',
        'retention_protected',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'category' => SupportTicketCategory::class,
            'status' => SupportTicketStatus::class,
            'assigned_admin_id' => 'integer',
            'closed_by_admin_id' => 'integer',
            'closed_by_user_id' => 'integer',
            'last_message_at' => 'datetime',
            'last_player_message_at' => 'datetime',
            'last_staff_message_at' => 'datetime',
            'closed_at' => 'datetime',
            'retention_protected' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    /** @return BelongsTo<Admin, $this> */
    public function closedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'closed_by_admin_id');
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')->orderBy('id');
    }

    /** @param Builder<SupportTicket> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '!=', SupportTicketStatus::Closed->value);
    }

    public function number(): string
    {
        return 'SUP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isClosed(): bool
    {
        return $this->status === SupportTicketStatus::Closed;
    }
}
