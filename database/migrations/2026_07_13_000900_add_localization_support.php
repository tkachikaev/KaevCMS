<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'locale')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('locale', 10)->default('ru')->index();
            });
        }

        if (Schema::hasTable('admins') && ! Schema::hasColumn('admins', 'locale')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->string('locale', 10)->default('ru')->index();
            });
        }

        if (Schema::hasTable('news') && ! Schema::hasTable('news_translations')) {
            Schema::create('news_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
                $table->string('locale', 10);
                $table->string('title');
                $table->string('slug');
                $table->text('excerpt')->nullable();
                $table->longText('body');
                $table->timestamps();

                $table->unique(['news_id', 'locale']);
                $table->unique(['locale', 'slug']);
                $table->index(['locale', 'news_id']);
            });
        }

        if (Schema::hasTable('game_servers') && ! Schema::hasTable('game_server_translations')) {
            Schema::create('game_server_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('game_server_id')->constrained('game_servers')->cascadeOnDelete();
                $table->string('locale', 10);
                $table->string('name', 100);
                $table->timestamps();

                $table->unique(['game_server_id', 'locale']);
            });
        }

        if (Schema::hasTable('news') && Schema::hasTable('news_translations')) {
            DB::table('news')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('news_translations')->updateOrInsert(
                        ['news_id' => $row->id, 'locale' => 'ru'],
                        [
                            'title' => $row->title,
                            'slug' => $row->slug,
                            'excerpt' => $row->excerpt,
                            'body' => $row->body,
                            'created_at' => $row->created_at ?? now(),
                            'updated_at' => $row->updated_at ?? now(),
                        ],
                    );
                }
            });
        }

        if (Schema::hasTable('game_servers') && Schema::hasTable('game_server_translations')) {
            DB::table('game_servers')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('game_server_translations')->updateOrInsert(
                        ['game_server_id' => $row->id, 'locale' => 'ru'],
                        [
                            'name' => $row->name,
                            'created_at' => $row->created_at ?? now(),
                            'updated_at' => $row->updated_at ?? now(),
                        ],
                    );
                }
            });
        }

        if (Schema::hasTable('cms_settings')) {
            $this->copySetting('site.name', 'site.name.ru');
            $this->copySetting('site.description', 'site.description.ru');
            $this->copySetting('site.footer_text', 'site.footer_text.ru');

            foreach (['email_verification', 'password_reset', 'password_changed'] as $template) {
                foreach (['subject', 'heading', 'body', 'action_text', 'footer'] as $field) {
                    $this->copySetting(
                        'mail.template.'.$template.'.'.$field,
                        'mail.template.'.$template.'.ru.'.$field,
                    );
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_server_translations');
        Schema::dropIfExists('news_translations');

        if (Schema::hasTable('admins') && Schema::hasColumn('admins', 'locale')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->dropIndex(['locale']);
                $table->dropColumn('locale');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'locale')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['locale']);
                $table->dropColumn('locale');
            });
        }
    }

    private function copySetting(string $source, string $destination): void
    {
        $existing = DB::table('cms_settings')->where('key', $destination)->exists();
        if ($existing) {
            return;
        }

        $sourceRow = DB::table('cms_settings')->where('key', $source)->first();
        if ($sourceRow === null) {
            return;
        }

        DB::table('cms_settings')->insert([
            'key' => $destination,
            'value' => $sourceRow->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
