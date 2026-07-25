<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('driver', 64);
            $table->string('database_host', 255);
            $table->unsignedSmallInteger('database_port')->default(3306);
            $table->string('database_name', 64);
            $table->string('database_username', 128);
            $table->text('database_password')->nullable();
            $table->string('database_charset', 32)->default('utf8mb4');
            $table->timestamps();
        });

        Schema::table('game_servers', function (Blueprint $table): void {
            $table->foreignId('login_server_id')->nullable()->constrained('login_servers')->restrictOnDelete();
            $table->string('driver', 64)->nullable();
            $table->boolean('use_login_server_connection')->default(true);
            $table->string('database_host', 255)->nullable();
            $table->unsignedSmallInteger('database_port')->nullable();
            $table->string('database_name', 64)->nullable();
            $table->string('database_username', 128)->nullable();
            $table->text('database_password')->nullable();
            $table->string('database_charset', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('login_server_id');
            $table->dropColumn([
                'driver',
                'use_login_server_connection',
                'database_host',
                'database_port',
                'database_name',
                'database_username',
                'database_password',
                'database_charset',
            ]);
        });

        Schema::dropIfExists('login_servers');
    }
};
