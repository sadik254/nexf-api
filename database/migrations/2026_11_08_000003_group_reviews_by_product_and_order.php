<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->after('order_item_id')->constrained()->nullOnDelete();
            $table->index(['customer_id', 'order_id', 'product_id'], 'reviews_customer_order_product_idx');
        });

        // Do not merge or delete legacy duplicate reviews. This only gives
        // existing rows their source order so new writes can hold the rule.
        DB::table('reviews')->orderBy('id')->each(function (object $review): void {
            if ($review->order_item_id === null) return;
            $orderId = DB::table('order_items')->where('id', $review->order_item_id)->value('order_id');
            if ($orderId !== null) DB::table('reviews')->where('id', $review->id)->update(['order_id' => $orderId]);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex('reviews_customer_order_product_idx');
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
