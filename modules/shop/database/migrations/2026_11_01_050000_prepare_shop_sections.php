<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The existing tabs become sections. Keep the physical table and its IDs:
        // purchases and older catalog data must not be rebuilt or reassigned.
        Schema::table('module_shop_products', function (Blueprint $table): void {
            $table->foreignId('section_id')->nullable()
                ->constrained('module_shop_tabs')->restrictOnDelete();
            $table->text('description_ru')->nullable();
            $table->text('description_en')->nullable();
        });

        // Copy the parent tab from each legacy category. Never guess a section
        // or silently discard a row if a legacy relation is unexpectedly broken.
        DB::table('module_shop_products')->select(['id', 'category_id'])
            ->orderBy('id')->chunkById(500, static function ($products): void {
                $categoryIds = $products->pluck('category_id')->unique()->values()->all();
                $sections = DB::table('module_shop_categories')
                    ->whereIn('id', $categoryIds)->pluck('tab_id', 'id');
                foreach ($products->groupBy('category_id') as $categoryId => $rows) {
                    $sectionId = $sections->get($categoryId);
                    if ($sectionId === null) {
                        throw new RuntimeException('Shop product has an orphaned legacy category.');
                    }
                    DB::table('module_shop_products')
                        ->whereIn('id', $rows->pluck('id')->all())
                        ->update(['section_id' => (int) $sectionId]);
                }
            });
    }

    public function down(): void
    {
        // Rollback removes only the new metadata; category and bundle links stay.
        Schema::table('module_shop_products', function (Blueprint $table): void {
            $table->dropForeign(['section_id']);
            $table->dropColumn(['section_id', 'description_ru', 'description_en']);
        });
    }
};
