<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_support_ticket_settings', function (Blueprint $table): void {
            $table->boolean('allow_editor_view')->default(false)->after('allow_editor_management');
            $table->boolean('allow_editor_reply')->default(false)->after('allow_editor_view');
            $table->boolean('allow_editor_internal_notes')->default(false)->after('allow_editor_reply');
            $table->unsignedSmallInteger('retention_months')->default(24)->after('allow_editor_internal_notes');
            $table->boolean('automatic_cleanup_enabled')->default(true)->after('retention_months');
        });

        DB::table('module_support_ticket_settings')
            ->where('allow_editor_management', true)
            ->update([
                'allow_editor_view' => true,
                'allow_editor_reply' => true,
                'allow_editor_internal_notes' => true,
            ]);

        Schema::table('module_support_tickets', function (Blueprint $table): void {
            $table->boolean('retention_protected')->default(false)->after('closed_at');
            $table->index(
                ['user_id', 'last_message_at', 'id'],
                'support_tickets_user_last_id_index',
            );
            $table->index(
                ['last_message_at', 'id'],
                'support_tickets_last_id_index',
            );
            $table->index(
                ['assigned_admin_id', 'last_message_at', 'id'],
                'support_tickets_assigned_last_id_index',
            );
            $table->index(
                ['status', 'closed_at', 'id'],
                'support_tickets_retention_index',
            );
            $table->index(
                ['user_id', 'created_at', 'id'],
                'support_tickets_user_created_id_index',
            );
        });

        Schema::table('module_support_ticket_messages', function (Blueprint $table): void {
            $table->index(
                ['author_type', 'user_id', 'created_at', 'id'],
                'support_messages_player_daily_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('module_support_tickets', function (Blueprint $table): void {
            $table->dropIndex('support_tickets_user_last_id_index');
            $table->dropIndex('support_tickets_last_id_index');
            $table->dropIndex('support_tickets_assigned_last_id_index');
            $table->dropIndex('support_tickets_retention_index');
            $table->dropIndex('support_tickets_user_created_id_index');
            $table->dropColumn('retention_protected');
        });

        Schema::table('module_support_ticket_messages', function (Blueprint $table): void {
            $table->dropIndex('support_messages_player_daily_index');
        });

        Schema::table('module_support_ticket_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'allow_editor_view',
                'allow_editor_reply',
                'allow_editor_internal_notes',
                'retention_months',
                'automatic_cleanup_enabled',
            ]);
        });
    }
};
