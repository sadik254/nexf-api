<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('product_variations')->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->nullOnDelete();
            $table->string('product_name');
            $table->string('product_slug')->nullable();
            $table->string('sku')->nullable();
            $table->json('variation_attributes')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_selling_price', 12, 2);
            $table->decimal('unit_buying_price', 12, 2);
            $table->decimal('line_subtotal', 12, 2);
            $table->decimal('line_cost', 12, 2);
            $table->decimal('line_profit', 12, 2);
            $table->json('lot_allocations')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'variation_id']);
            $table->index(['seller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
