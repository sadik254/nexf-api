<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('courier_provider')->nullable()->after('tracking_number');
            $table->string('courier_consignment_id')->nullable()->after('courier_provider');
            $table->string('courier_invoice')->nullable()->after('courier_consignment_id');
            $table->string('courier_status')->nullable()->after('courier_invoice');
            $table->timestamp('courier_created_at')->nullable()->after('courier_status');
            $table->timestamp('courier_updated_at')->nullable()->after('courier_created_at');
            $table->index(['courier_provider', 'courier_consignment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['courier_provider', 'courier_consignment_id']);
            $table->dropColumn(['courier_provider', 'courier_consignment_id', 'courier_invoice', 'courier_status', 'courier_created_at', 'courier_updated_at']);
        });
    }
};
