<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discount a store gives while editing its own parcel. It comes off that
 * parcel only and the store pays it; NEXF's own edit discount stays on the
 * order (`orders.manual_discount_*`) and comes off the parcels NEXF ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_store_groups', function (Blueprint $table) {
            $table->string('manual_discount_kind', 16)->nullable();
            $table->decimal('manual_discount_value', 12, 2)->nullable();
            $table->decimal('manual_discount', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('order_store_groups', function (Blueprint $table) {
            $table->dropColumn(['manual_discount_kind', 'manual_discount_value', 'manual_discount']);
        });
    }
};
