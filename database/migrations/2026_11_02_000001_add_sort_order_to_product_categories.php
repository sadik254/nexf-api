<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('parent_id');
            $table->index(['parent_id', 'sort_order']);
        });

        // Preserve the A-Z order already shown before this feature shipped.
        DB::table('product_categories')->orderBy('name')->get(['id', 'parent_id'])
            ->groupBy(fn ($row) => $row->parent_id ?? 0)
            ->each(fn ($rows) => $rows->values()->each(
                fn ($row, $position) => DB::table('product_categories')->where('id', $row->id)->update(['sort_order' => $position])
            ));
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
