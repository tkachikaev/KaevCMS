<?php

namespace KaevCMS\Modules\SupportTickets\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $message_id
 * @property int|null $editor_admin_id
 * @property string $editor_name_snapshot
 * @property string $previous_body
 * @property Carbon $edited_at
 */
final class SupportTicketMessageRevision extends Model
{
    public $timestamps = false;

    protected $table = 'module_support_ticket_message_revisions';

    protected $fillable = [
        'message_id',
        'editor_admin_id',
        'editor_name_snapshot',
        'previous_body',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'editor_admin_id' => 'integer',
            'edited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupportTicketMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'message_id');
    }

    /** @return BelongsTo<Admin, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'editor_admin_id');
    }
}
