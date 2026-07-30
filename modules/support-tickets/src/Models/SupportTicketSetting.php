<?php

namespace KaevCMS\Modules\SupportTickets\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportTicketSetting extends Model
{
    protected $table = 'module_support_ticket_settings';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'allow_editor_management',
        'allow_editor_view',
        'allow_editor_reply',
        'allow_editor_internal_notes',
        'retention_months',
        'automatic_cleanup_enabled',
        'max_tickets_per_day',
        'max_player_messages_per_day',
        'max_messages_per_ticket',
        'max_revisions_per_message',
        'max_open_tickets_per_user',
        'subject_max_length',
        'initial_message_max_length',
        'message_max_length',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'allow_editor_management' => 'boolean',
            'allow_editor_view' => 'boolean',
            'allow_editor_reply' => 'boolean',
            'allow_editor_internal_notes' => 'boolean',
            'retention_months' => 'integer',
            'automatic_cleanup_enabled' => 'boolean',
            'max_tickets_per_day' => 'integer',
            'max_player_messages_per_day' => 'integer',
            'max_messages_per_ticket' => 'integer',
            'max_revisions_per_message' => 'integer',
            'max_open_tickets_per_user' => 'integer',
            'subject_max_length' => 'integer',
            'initial_message_max_length' => 'integer',
            'message_max_length' => 'integer',
            'updated_by_admin_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Admin, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }
}
