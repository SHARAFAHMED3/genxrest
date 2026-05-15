<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_item_categories')) {
            return;
        }

        if (!Schema::hasColumn('inventory_item_categories', 'restaurant_id')) {
            Schema::table('inventory_item_categories', function (Blueprint $table) {
                $table->unsignedBigInteger('restaurant_id')->nullable()->after('id');
                $table->index('restaurant_id');
            });
        }

        if (Schema::hasColumn('inventory_item_categories', 'branch_id')) {
            DB::statement(<<<'SQL'
UPDATE inventory_item_categories c
INNER JOIN branches b ON b.id = c.branch_id
SET c.restaurant_id = b.restaurant_id
WHERE c.restaurant_id IS NULL
SQL
            );
        }

        $restaurantCount = (int) DB::table('restaurants')->count();
        if ($restaurantCount === 1) {
            $restaurantId = (int) DB::table('restaurants')->min('id');
            DB::table('inventory_item_categories')
                ->whereNull('restaurant_id')
                ->update(['restaurant_id' => $restaurantId]);
        }

        $existing = DB::select(<<<'SQL'
SELECT CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'inventory_item_categories'
  AND COLUMN_NAME = 'restaurant_id'
  AND REFERENCED_TABLE_NAME IS NOT NULL
SQL
        );

        if (empty($existing) && Schema::hasColumn('inventory_item_categories', 'restaurant_id')) {
            Schema::table('inventory_item_categories', function (Blueprint $table) {
                $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('inventory_item_categories') || !Schema::hasColumn('inventory_item_categories', 'restaurant_id')) {
            return;
        }

        $foreignKeys = DB::select(<<<'SQL'
SELECT CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'inventory_item_categories'
  AND COLUMN_NAME = 'restaurant_id'
  AND REFERENCED_TABLE_NAME IS NOT NULL
SQL
        );

        Schema::table('inventory_item_categories', function (Blueprint $table) use ($foreignKeys) {
            foreach ($foreignKeys as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }
            $table->dropIndex(['restaurant_id']);
            $table->dropColumn('restaurant_id');
        });
    }
};
