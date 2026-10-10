<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreignId('customer_id')->nullable()->change();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->string('shipping_email')->nullable()->after('shipping_phone');
        });
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNull('customer_id')->exists()) {
            throw new \RuntimeException('Cannot make order customer links required while guest orders exist.');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreignId('customer_id')->nullable(false)->change();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->dropColumn('shipping_email');
        });
    }
};
