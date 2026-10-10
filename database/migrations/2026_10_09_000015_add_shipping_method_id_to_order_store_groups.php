<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_store_groups', function (Blueprint $table) {
            $table->foreignId('shipping_method_id')->nullable()->after('seller_id')->constrained('shipping_methods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_store_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_method_id');
        });
    }
};
