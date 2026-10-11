<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // MySQL DDL is not transactional. A failed later statement may leave
        // earlier tables behind while Laravel keeps this migration pending.
        // Guard each table so deploying the fixed migration can be retried.
        if (!Schema::hasTable('customer_wishlist_items')) {
            Schema::create('customer_wishlist_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['customer_id', 'product_id']);
            });
        }
        if (!Schema::hasTable('customer_followed_stores')) {
            Schema::create('customer_followed_stores', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['customer_id', 'seller_id']);
            });
        }
        if (!Schema::hasTable('customer_restock_alerts')) {
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
        }
        if (!Schema::hasTable('customer_in_site_notifications')) {
            Schema::create('customer_in_site_notifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('type', 80);
                $table->string('title', 180);
                $table->text('body')->nullable();
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
        // Keep this explicit name under MySQL's 64-character identifier cap.
        if (!Schema::hasIndex('customer_in_site_notifications', 'csn_customer_read_created_idx')) {
            Schema::table('customer_in_site_notifications', function (Blueprint $table): void {
                $table->index(['customer_id', 'read_at', 'created_at'], 'csn_customer_read_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_in_site_notifications');
        Schema::dropIfExists('customer_restock_alerts');
        Schema::dropIfExists('customer_followed_stores');
        Schema::dropIfExists('customer_wishlist_items');
    }
};
