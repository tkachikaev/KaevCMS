<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Names and descriptions for every enabled site language. The legacy
        // name_ru/name_en columns stay as a fallback and for purchase snapshots.
        Schema::create('module_shop_section_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained('module_shop_tabs')->cascadeOnDelete();
            $table->string('locale', 32);
            $table->string('name', 120);
            $table->timestamps();
            $table->unique(['section_id', 'locale'], 'shop_section_translation_unique');
        });

        Schema::create('module_shop_product_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('module_shop_products')->cascadeOnDelete();
            $table->string('locale', 32);
            $table->string('name', 120);
            $table->text('description')->nullable();
            // Manual names are kept when the item composition changes.
            $table->boolean('name_manual')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'locale'], 'shop_product_translation_unique');
        });

        $now = now();
        DB::table('module_shop_tabs')->orderBy('id')->chunkById(500, static function ($sections) use ($now): void {
            $rows = [];
            foreach ($sections as $section) {
                foreach (['ru' => $section->name_ru, 'en' => $section->name_en] as $locale => $name) {
                    if (trim((string) $name) !== '') {
                        $rows[] = ['section_id' => $section->id, 'locale' => $locale, 'name' => $name,
                            'created_at' => $now, 'updated_at' => $now];
                    }
                }
            }
            DB::table('module_shop_section_translations')->insert($rows);
        });

        DB::table('module_shop_products')->orderBy('id')->chunkById(500, static function ($products) use ($now): void {
            $rows = [];
            foreach ($products as $product) {
                foreach (['ru', 'en'] as $locale) {
                    $name = (string) $product->{'name_'.$locale};
                    if (trim($name) === '') {
                        continue;
                    }
                    $description = trim((string) $product->{'description_'.$locale});
                    $rows[] = ['product_id' => $product->id, 'locale' => $locale, 'name' => $name,
                        'description' => $description !== '' ? $description : null, 'name_manual' => true,
                        'created_at' => $now, 'updated_at' => $now];
                }
            }
            DB::table('module_shop_product_translations')->insert($rows);
        });
    }

    public function down(): void
    {
        // The legacy columns were kept in sync, so dropping the tables loses no RU/EN data.
        Schema::dropIfExists('module_shop_product_translations');
        Schema::dropIfExists('module_shop_section_translations');
    }
};
