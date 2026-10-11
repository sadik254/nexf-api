<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_area', 150)->nullable();
            $table->string('shipping_district', 150)->nullable();
            $table->string('device_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['device_id']);
            $table->dropColumn(['shipping_area', 'shipping_district', 'device_id']);
        });
    }
};
