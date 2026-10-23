<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('staff_order')->default(false)->after('is_guest')->index();
        });
        Schema::table('fraud_guard_settings', function (Blueprint $table) {
            $table->boolean('repeat_order_block')->default(false)->after('fake_number_detection');
            $table->unsignedInteger('repeat_order_minutes')->default(5)->after('repeat_order_block');
        });
    }
    public function down(): void
    {
        Schema::table('fraud_guard_settings', function (Blueprint $table) {
            $table->dropColumn(['repeat_order_block', 'repeat_order_minutes']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['staff_order']);
            $table->dropColumn('staff_order');
        });
    }
};
