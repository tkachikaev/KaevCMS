<?php

namespace KaevCMS\Modules\SupportTickets\Services;

use App\Models\Admin;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketSetting;
use Throwable;

final class SupportTicketSettings
{
    public function editorsCanManage(): bool
    {
        try {
            if (! Schema::hasTable('module_support_ticket_settings')) {
                return false;
            }

            return (bool) SupportTicketSetting::query()->whereKey(1)->value('allow_editor_management');
        } catch (Throwable) {
            return false;
        }
    }

    public function update(bool $allowEditorManagement, Admin $admin): SupportTicketSetting
    {
        return SupportTicketSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'allow_editor_management' => $allowEditorManagement,
                'updated_by_admin_id' => $admin->id,
            ],
        );
    }
}
