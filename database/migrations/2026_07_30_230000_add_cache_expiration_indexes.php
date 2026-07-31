<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cache') && ! $this->hasIndex('cache', 'cache_expiration_index')) {
            Schema::table('cache', function (Blueprint $table): void {
                $table->index('expiration', 'cache_expiration_index');
            });
        }

        if (Schema::hasTable('cache_locks') && ! $this->hasIndex('cache_locks', 'cache_locks_expiration_index')) {
            Schema::table('cache_locks', function (Blueprint $table): void {
                $table->index('expiration', 'cache_locks_expiration_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cache') && $this->hasIndex('cache', 'cache_expiration_index')) {
            Schema::table('cache', function (Blueprint $table): void {
                $table->dropIndex('cache_expiration_index');
            });
        }

        if (Schema::hasTable('cache_locks') && $this->hasIndex('cache_locks', 'cache_locks_expiration_index')) {
            Schema::table('cache_locks', function (Blueprint $table): void {
                $table->dropIndex('cache_locks_expiration_index');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return in_array($index, array_column(Schema::getIndexes($table), 'name'), true);
    }
};
