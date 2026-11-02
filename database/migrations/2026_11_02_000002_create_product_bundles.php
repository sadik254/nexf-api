<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_bundles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->string('mode', 12);
            $table->unsignedInteger('size');
            $table->decimal('price', 12, 2);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('product_bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_id')->constrained('product_bundles')->cascadeOnDelete();
            $table->json('attributes')->nullable();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('bundle_label', 60)->nullable()->after('variation_image');
            $table->json('bundle_items')->nullable()->after('bundle_label');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['bundle_label', 'bundle_items']));
        Schema::dropIfExists('product_bundle_items');
        Schema::dropIfExists('product_bundles');
    }
};
