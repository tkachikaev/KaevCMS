<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateLogin = DB::table('users')
            ->selectRaw('LOWER(name) as normalized_name, COUNT(*) as aggregate')
            ->groupByRaw('LOWER(name)')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateLogin !== null) {
            throw new RuntimeException('Duplicate user logins were found. Rename duplicates before upgrading KaevCMS.');
        }

        $duplicateEmail = DB::table('users')
            ->selectRaw('LOWER(email) as normalized_email, COUNT(*) as aggregate')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateEmail !== null) {
            throw new RuntimeException('Duplicate user emails were found. Rename duplicates before upgrading KaevCMS.');
        }

        DB::table('users')->orderBy('id')->get(['id', 'name', 'email'])->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update([
                'name' => strtolower(trim((string) $user->name)),
                'email' => strtolower(trim((string) $user->email)),
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('name', 'users_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_name_unique');
        });
    }
};
