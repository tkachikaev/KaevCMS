<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('module_support_tickets')->cascadeOnDelete();
            $table->string('author_type', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('author_name_snapshot', 190);
            $table->string('admin_role_snapshot', 40)->nullable();
            $table->boolean('is_internal')->default(false);
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'id'], 'support_ticket_messages_ticket_id_index');
            $table->index(['admin_id', 'created_at'], 'support_ticket_messages_admin_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_support_ticket_messages');
    }
};
