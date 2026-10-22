<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('sku_prefix', 4)->nullable()->after('logo');
            $table->string('support_email')->nullable()->after('sku_prefix');
            $table->string('support_phone', 32)->nullable()->after('support_email');
            $table->string('address_line')->nullable()->after('support_phone');
            $table->string('address_area')->nullable()->after('address_line');
            $table->string('address_district')->nullable()->after('address_area');
            $table->text('store_address')->nullable()->after('address_district');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['sku_prefix', 'support_email', 'support_phone', 'address_line', 'address_area', 'address_district', 'store_address']);
        });
    }
};
