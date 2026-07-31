<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_support_ticket_settings')
            || DB::table('module_support_ticket_settings')->where('id', 1)->exists()) {
            return;
        }

        DB::table('module_support_ticket_settings')->insert([
            'id' => 1,
            'allow_editor_management' => false,
            'allow_editor_view' => false,
            'allow_editor_reply' => false,
            'allow_editor_internal_notes' => false,
            'retention_months' => SupportTicketSettings::DEFAULT_RETENTION_MONTHS,
            'automatic_cleanup_enabled' => true,
            'max_tickets_per_day' => SupportTicketSettings::DEFAULT_MAX_TICKETS_PER_DAY,
            'max_player_messages_per_day' => SupportTicketSettings::DEFAULT_MAX_PLAYER_MESSAGES_PER_DAY,
            'max_messages_per_ticket' => SupportTicketSettings::DEFAULT_MAX_MESSAGES_PER_TICKET,
            'max_revisions_per_message' => SupportTicketSettings::DEFAULT_MAX_REVISIONS_PER_MESSAGE,
            'max_open_tickets_per_user' => SupportTicketSettings::DEFAULT_MAX_OPEN_TICKETS_PER_USER,
            'subject_max_length' => SupportTicketSettings::DEFAULT_SUBJECT_MAX_LENGTH,
            'initial_message_max_length' => SupportTicketSettings::DEFAULT_INITIAL_MESSAGE_MAX_LENGTH,
            'message_max_length' => SupportTicketSettings::DEFAULT_MESSAGE_MAX_LENGTH,
            'updated_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // The settings row can contain owner changes and must not be deleted during rollback.
    }
};
