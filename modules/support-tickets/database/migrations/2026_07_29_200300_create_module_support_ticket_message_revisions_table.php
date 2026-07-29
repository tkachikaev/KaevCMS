<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_support_ticket_message_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('module_support_ticket_messages')->cascadeOnDelete();
            $table->foreignId('editor_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('editor_name_snapshot', 190);
            $table->text('previous_body');
            $table->timestamp('edited_at');

            $table->index(['message_id', 'edited_at'], 'support_message_revisions_message_edited_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_support_ticket_message_revisions');
    }
};
