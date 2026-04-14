<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('product_variations')->cascadeOnDelete();
            $table->string('lot_number');
            $table->decimal('buying_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->integer('quantity');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'variation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_lots');
    }
};
