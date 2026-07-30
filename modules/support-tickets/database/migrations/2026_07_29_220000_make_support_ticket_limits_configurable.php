<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_support_ticket_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_tickets_per_day')->default(10)->after('automatic_cleanup_enabled');
            $table->unsignedSmallInteger('max_player_messages_per_day')->default(100)->after('max_tickets_per_day');
            $table->unsignedSmallInteger('max_messages_per_ticket')->default(300)->after('max_player_messages_per_day');
            $table->unsignedSmallInteger('max_revisions_per_message')->default(20)->after('max_messages_per_ticket');
            $table->unsignedSmallInteger('max_open_tickets_per_user')->default(5)->after('max_revisions_per_message');
            $table->unsignedSmallInteger('subject_max_length')->default(120)->after('max_open_tickets_per_user');
            $table->unsignedSmallInteger('initial_message_max_length')->default(3000)->after('subject_max_length');
            $table->unsignedSmallInteger('message_max_length')->default(2000)->after('initial_message_max_length');
        });
    }

    public function down(): void
    {
        Schema::table('module_support_ticket_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'max_tickets_per_day',
                'max_player_messages_per_day',
                'max_messages_per_ticket',
                'max_revisions_per_message',
                'max_open_tickets_per_user',
                'subject_max_length',
                'initial_message_max_length',
                'message_max_length',
            ]);
        });
    }
};
