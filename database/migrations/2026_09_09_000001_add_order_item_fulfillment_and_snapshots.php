<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product_thumbnail')->nullable()->after('product_slug');
            $table->json('product_gallery')->nullable()->after('product_thumbnail');
            $table->json('product_image_variants')->nullable()->after('product_gallery');
            $table->enum('fulfillment_status', ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'])->default('pending')->after('lot_allocations');
            $table->string('tracking_number')->nullable()->after('fulfillment_status');
            $table->timestamp('shipped_at')->nullable()->after('tracking_number');
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status ENUM('pending', 'confirmed', 'shipped', 'delivered', 'cancelled', 'completed') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'product_thumbnail',
                'product_gallery',
                'product_image_variants',
                'fulfillment_status',
                'tracking_number',
                'shipped_at',
                'delivered_at',
            ]);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'pending'");
        }
    }
};
