<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE order_items MODIFY fulfillment_status ENUM('pending','confirmed','shipped','delivered','return_pending','returned','cancelled') NOT NULL DEFAULT 'pending'");
            return;
        }

        if (DB::getDriverName() !== 'sqlite') return;
        Schema::disableForeignKeyConstraints();
        Schema::rename('order_items', 'order_items_old');
        Schema::create('order_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('order_id'); $table->foreignId('product_id')->nullable(); $table->foreignId('variation_id')->nullable(); $table->foreignId('seller_id')->nullable();
            $table->string('product_name'); $table->string('product_slug')->nullable(); $table->string('sku')->nullable(); $table->json('variation_attributes')->nullable(); $table->unsignedInteger('quantity');
            $table->decimal('unit_selling_price', 12, 2); $table->decimal('unit_buying_price', 12, 2); $table->decimal('line_subtotal', 12, 2); $table->decimal('line_cost', 12, 2); $table->decimal('line_profit', 12, 2); $table->json('lot_allocations')->nullable();
            $table->string('product_thumbnail')->nullable(); $table->json('product_gallery')->nullable(); $table->json('product_image_variants')->nullable();
            $table->enum('fulfillment_status', ['pending','confirmed','shipped','delivered','return_pending','returned','cancelled'])->default('pending'); $table->string('tracking_number')->nullable(); $table->timestamp('shipped_at')->nullable(); $table->timestamp('delivered_at')->nullable();
            $table->string('courier_provider')->nullable(); $table->string('courier_consignment_id')->nullable(); $table->string('courier_invoice')->nullable(); $table->string('courier_status')->nullable(); $table->timestamp('courier_created_at')->nullable(); $table->timestamp('courier_updated_at')->nullable(); $table->text('courier_error')->nullable(); $table->text('courier_tracking_message')->nullable(); $table->timestamps();
        });
        DB::statement('INSERT INTO order_items SELECT * FROM order_items_old');
        Schema::drop('order_items_old');
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE order_items MODIFY fulfillment_status ENUM('pending','confirmed','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending'");
    }
};
