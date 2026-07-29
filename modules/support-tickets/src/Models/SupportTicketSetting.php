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
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'allow_editor_management' => 'boolean',
            'updated_by_admin_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Admin, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }
}
