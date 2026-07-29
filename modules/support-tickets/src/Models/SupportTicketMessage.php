<?php

namespace KaevCMS\Modules\SupportTickets\Models;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ticket_id
 * @property string $author_type
 * @property int|null $user_id
 * @property int|null $admin_id
 * @property string $author_name_snapshot
 * @property string|null $admin_role_snapshot
 * @property bool $is_internal
 * @property string $body
 * @property Carbon|null $edited_at
 * @property-read SupportTicket $ticket
 * @property-read Collection<int, SupportTicketMessageRevision> $revisions
 */
final class SupportTicketMessage extends Model
{
    public const AUTHOR_PLAYER = 'player';
    public const AUTHOR_ADMIN = 'admin';
    public const AUTHOR_SYSTEM = 'system';

    protected $table = 'module_support_ticket_messages';

    protected $fillable = [
        'ticket_id',
        'author_type',
        'user_id',
        'admin_id',
        'author_name_snapshot',
        'admin_role_snapshot',
        'is_internal',
        'body',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'user_id' => 'integer',
            'admin_id' => 'integer',
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /** @return HasMany<SupportTicketMessageRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(SupportTicketMessageRevision::class, 'message_id')->orderByDesc('edited_at');
    }

    public function isStaffMessage(): bool
    {
        return $this->author_type === self::AUTHOR_ADMIN;
    }
}
