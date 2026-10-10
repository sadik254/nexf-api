<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('manual_discount_kind', 16)->nullable()->after('discount_total');
            $table->decimal('manual_discount_value', 12, 2)->nullable()->after('manual_discount_kind');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['manual_discount_kind', 'manual_discount_value']);
        });
    }
};
