<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name_snapshot', 190);
            $table->string('user_email_snapshot', 190);
            $table->string('category', 40);
            $table->string('status', 40);
            $table->string('subject', 120);
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('closed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at');
            $table->timestamp('last_player_message_at')->nullable();
            $table->timestamp('last_staff_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'last_message_at'], 'support_tickets_user_status_last_index');
            $table->index(['status', 'last_message_at'], 'support_tickets_status_last_index');
            $table->index(['category', 'status'], 'support_tickets_category_status_index');
            $table->index(['assigned_admin_id', 'status'], 'support_tickets_assigned_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_support_tickets');
    }
};
