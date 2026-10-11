<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'product_id']);
        });
        Schema::create('customer_followed_stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'seller_id']);
        });
        Schema::create('customer_restock_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('product_variations')->cascadeOnDelete();
            $table->string('variation_key', 32);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'product_id', 'variation_key'], 'customer_restock_alert_unique');
        });
        Schema::create('customer_in_site_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('title', 180);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_in_site_notifications');
        Schema::dropIfExists('customer_restock_alerts');
        Schema::dropIfExists('customer_followed_stores');
        Schema::dropIfExists('customer_wishlist_items');
    }
};
