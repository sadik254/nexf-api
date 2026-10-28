<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('wallet_amount', 12, 2)->nullable()->after('paid_amount');
            $table->timestamp('payment_rejected_at')->nullable()->after('payment_received_at');
            $table->string('cancelled_by', 16)->nullable()->after('cancelled_at');
            $table->decimal('reseller_commission_rate', 8, 4)->nullable();
            $table->unsignedInteger('reseller_monthly_target')->nullable();
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->json('variation_labels')->nullable()->after('variation_attributes');
            $table->text('variation_image')->nullable()->after('variation_labels');
            $table->decimal('commission_rate', 8, 4)->nullable()->after('line_profit');
        });
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->string('coupon_name')->nullable()->after('coupon_code');
            $table->boolean('is_automatic')->default(false)->after('coupon_name');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', fn (Blueprint $table) => $table->dropColumn(['coupon_name', 'is_automatic']));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['variation_labels', 'variation_image', 'commission_rate']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['wallet_amount', 'payment_rejected_at', 'cancelled_by', 'reseller_commission_rate', 'reseller_monthly_target']));
    }
};
