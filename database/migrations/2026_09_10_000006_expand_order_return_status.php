<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','confirmed','shipped','delivered','return_pending','cancelled','completed') NOT NULL DEFAULT 'pending'");
            return;
        }
        if (DB::getDriverName() !== 'sqlite') return;
        Schema::disableForeignKeyConstraints(); Schema::rename('orders', 'orders_old');
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->string('order_number'); $t->foreignId('customer_id'); $t->foreignId('payment_method_id')->nullable(); $t->foreignId('shipping_method_id')->nullable(); $t->foreignId('coupon_id')->nullable();
            $t->enum('status', ['pending','confirmed','shipped','delivered','return_pending','cancelled','completed'])->default('pending'); $t->enum('payment_status', ['unpaid','paid','failed','refunded'])->default('unpaid');
            $t->string('payment_method_code')->nullable(); $t->string('payment_method_name')->nullable(); $t->string('shipping_method_code')->nullable(); $t->string('shipping_method_name')->nullable(); $t->decimal('shipping_charge',12,2)->default(0); $t->string('shipping_currency',3)->default('BDT'); $t->string('coupon_code')->nullable(); $t->decimal('subtotal',12,2)->default(0); $t->decimal('discount_total',12,2)->default(0); $t->decimal('total',12,2)->default(0); $t->string('shipping_name'); $t->string('shipping_phone',32); $t->text('shipping_address'); $t->text('notes')->nullable(); $t->timestamp('placed_at')->nullable(); $t->timestamp('cancelled_at')->nullable(); $t->timestamps();
            $t->unique('order_number', 'orders_order_number_unique_v2'); $t->index(['customer_id','status'], 'orders_customer_status_index_v2'); $t->index('payment_status', 'orders_payment_status_index_v2');
        });
        DB::statement('INSERT INTO orders SELECT * FROM orders_old'); Schema::drop('orders_old'); Schema::enableForeignKeyConstraints();
    }
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','confirmed','shipped','delivered','cancelled','completed') NOT NULL DEFAULT 'pending'");
    }
};
