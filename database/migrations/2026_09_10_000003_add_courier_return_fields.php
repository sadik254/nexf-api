<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('order_items', function (Blueprint $table) { $table->text('courier_tracking_message')->nullable()->after('courier_error'); });
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE order_items MODIFY fulfillment_status ENUM('pending','confirmed','shipped','delivered','return_pending','returned','cancelled') NOT NULL DEFAULT 'pending'");
    }
    public function down(): void {
        Schema::table('order_items', function (Blueprint $table) { $table->dropColumn('courier_tracking_message'); });
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE order_items MODIFY fulfillment_status ENUM('pending','confirmed','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending'");
    }
};
