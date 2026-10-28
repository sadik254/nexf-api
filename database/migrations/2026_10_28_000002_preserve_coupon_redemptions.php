<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->dropForeign(['coupon_id']);
            $table->unsignedBigInteger('coupon_id')->nullable()->change();
            $table->foreign('coupon_id')->references('id')->on('coupons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->dropForeign(['coupon_id']);
            $table->unsignedBigInteger('coupon_id')->nullable(false)->change();
            $table->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
        });
    }
};
