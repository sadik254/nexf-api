<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $ids = DB::table('products')->where('status', 'active')->latest()->pluck('id');
        foreach ($ids->take(20)->values() as $order => $id) {
            DB::table('products')->where('id', $id)->update([
                'homepage_featured' => true,
                'homepage_trending' => $order < 10,
                'homepage_new_arrival' => $order < 10,
                'homepage_sort_order' => $order,
            ]);
        }
    }
    public function down(): void {}
};
