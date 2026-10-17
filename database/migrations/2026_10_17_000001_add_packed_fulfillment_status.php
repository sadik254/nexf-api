<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','confirmed','packed','shipped','delivered','return_pending','cancelled','completed') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE order_items MODIFY fulfillment_status ENUM('pending','confirmed','packed','shipped','delivered','return_pending','returned','cancelled') NOT NULL DEFAULT 'pending'");
        DB::statement("UPDATE orders SET status = 'packed' WHERE status = 'confirmed' AND packed_at IS NOT NULL");
        DB::statement("UPDATE order_items oi INNER JOIN orders o ON o.id = oi.order_id SET oi.fulfillment_status = 'packed' WHERE oi.fulfillment_status = 'confirmed' AND o.status = 'packed'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        DB::statement("UPDATE order_items SET fulfillment_status = 'confirmed' WHERE fulfillment_status = 'packed'");
        DB::statement("UPDATE orders SET status = 'confirmed' WHERE status = 'packed'");
        DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','confirmed','shipped','delivered','return_pending','cancelled','completed') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE order_items MODIFY fulfillment_status ENUM('pending','confirmed','shipped','delivered','return_pending','returned','cancelled') NOT NULL DEFAULT 'pending'");
    }
};
