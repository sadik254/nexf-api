<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('code', 80)->nullable();
            $table->string('discount_label', 60);
            $table->date('expires_at')->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->string('theme', 24)->default('blue');
            $table->boolean('pinned')->default(false);
            $table->timestamps();
            $table->index(['pinned', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_promotions');
    }
};
