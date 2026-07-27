<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_game_accounts', function (Blueprint $table): void {
            $table->uuid('creation_uuid')->nullable()->unique();
            $table->string('creation_status', 16)->default('active')->index();
            $table->text('creation_credential')->nullable();
            $table->text('creation_email')->nullable();
            $table->unsignedInteger('creation_attempts')->default(0);
            $table->string('creation_last_error', 64)->nullable();
            $table->timestamp('creation_write_attempted_at')->nullable();
            $table->timestamp('creation_processing_at')->nullable();
            $table->timestamp('creation_last_checked_at')->nullable();
            $table->timestamp('creation_completed_at')->nullable();
        });

        DB::table('user_game_accounts')
            ->select('id')
            ->orderBy('id')
            ->eachById(static function (object $account): void {
                DB::table('user_game_accounts')
                    ->where('id', $account->id)
                    ->update([
                        'creation_uuid' => (string) Str::uuid(),
                        'creation_status' => 'active',
                        'creation_completed_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('user_game_accounts', function (Blueprint $table): void {
            $table->dropUnique(['creation_uuid']);
            $table->dropIndex(['creation_status']);
            $table->dropColumn([
                'creation_uuid',
                'creation_status',
                'creation_credential',
                'creation_email',
                'creation_attempts',
                'creation_last_error',
                'creation_write_attempted_at',
                'creation_processing_at',
                'creation_last_checked_at',
                'creation_completed_at',
            ]);
        });
    }
};
