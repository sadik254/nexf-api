<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
            $table->decimal('charge', 12, 2);
            $table->timestamps();
            $table->unique(['seller_id', 'shipping_method_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('seller_shipping_rates'); }
};
