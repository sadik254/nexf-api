<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('house_only')->default(false)->after('seller_id');
        });

        Schema::table('product_collections', function (Blueprint $table) {
            $table->boolean('house_only')->default(false)->after('seller_id');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('house_only');
        });

        Schema::table('product_collections', function (Blueprint $table) {
            $table->dropColumn('house_only');
        });
    }
};
