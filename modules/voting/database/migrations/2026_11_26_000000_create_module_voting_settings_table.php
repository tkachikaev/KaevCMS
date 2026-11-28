<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_voting_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_code', 40)->unique();
            $table->boolean('enabled')->default(false);
            $table->uuid('project_id')->nullable();
            $table->text('api_key_ciphertext')->nullable();
            $table->string('pending_policy', 16)->default('wait');
            $table->unsignedBigInteger('credential_revision')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_result', 32)->nullable();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('module_voting_settings')->insert([
            'provider_code' => 'ktreon',
            'enabled' => false,
            'pending_policy' => 'wait',
            'credential_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('module_voting_settings');
    }
};
