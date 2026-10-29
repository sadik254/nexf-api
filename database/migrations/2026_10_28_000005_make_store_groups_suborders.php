<?php

use App\Models\Order;
use App\Services\SubOrderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each store group becomes the seller's sub-order: its own number, status,
 * share of the discount and payment, COD and parcel. NEXF ships its own
 * groups through Steadfast; third-party stores ship theirs and collect the COD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_store_groups', function (Blueprint $table) {
            $table->string('number', 60)->nullable()->unique()->after('order_id');
            $table->string('ships_by', 10)->default('nexf')->after('seller_id');
            $table->string('status', 20)->default('pending')->after('ships_by');
            $table->decimal('discount_share', 12, 2)->default(0)->after('shipping_charge');
            $table->decimal('seller_funded_discount', 12, 2)->default(0)->after('discount_share');
            $table->decimal('prepaid_amount', 12, 2)->default(0)->after('seller_funded_discount');
            $table->decimal('cod_amount', 12, 2)->default(0)->after('prepaid_amount');
            $table->string('courier_provider')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('courier_tracking_url')->nullable();
            $table->string('courier_consignment_id')->nullable()->index();
            $table->string('courier_invoice')->nullable()->index();
            $table->string('courier_status')->nullable();
            $table->text('courier_error')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('store_group_id')->nullable()->after('order_id')->constrained('order_store_groups')->nullOnDelete();
        });

        Schema::table('return_requests', function (Blueprint $table) {
            // Where the parcel goes back to: the store that shipped it.
            $table->string('return_to_name')->nullable();
            $table->string('return_to_phone', 32)->nullable();
            $table->text('return_to_address')->nullable();
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('escalated_at')->nullable();
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('seller_responded_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
        });

        // Existing orders: link lines to their store group, number the groups,
        // split the money and copy each parcel's courier details up.
        $service = app(SubOrderService::class);
        Order::query()->with(['items', 'storeGroups'])->chunkById(200, function ($orders) use ($service) {
            foreach ($orders as $order) $service->backfill($order);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', fn (Blueprint $table) => $table->dropColumn(['respond_by', 'seller_responded_at', 'escalated_at']));
        Schema::table('return_requests', fn (Blueprint $table) => $table->dropColumn(['return_to_name', 'return_to_phone', 'return_to_address', 'respond_by', 'escalated_at']));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('store_group_id'));
        Schema::table('order_store_groups', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropIndex(['courier_consignment_id']);
            $table->dropIndex(['courier_invoice']);
            $table->dropColumn([
                'number', 'ships_by', 'status', 'discount_share', 'seller_funded_discount', 'prepaid_amount', 'cod_amount',
                'courier_provider', 'tracking_number', 'courier_tracking_url', 'courier_consignment_id', 'courier_invoice',
                'courier_status', 'courier_error', 'packed_at', 'shipped_at', 'delivered_at',
            ]);
        });
    }
};
