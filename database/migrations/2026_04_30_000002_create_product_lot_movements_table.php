<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_lot_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_lot_id')->constrained('product_lots')->cascadeOnDelete();
            $table->integer('quantity_change'); // negative = consumed, positive = adjustment/return
            $table->string('reason', 32); // e.g. sale
            $table->string('actor_type', 64);
            $table->unsignedBigInteger('actor_id');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['actor_type', 'actor_id']);
            $table->index(['reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_lot_movements');
    }
};
