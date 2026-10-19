<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_wallet_transactions', function (Blueprint $table) {
            $table->string('source_key', 100)->nullable()->unique()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('customer_wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });
    }
};
