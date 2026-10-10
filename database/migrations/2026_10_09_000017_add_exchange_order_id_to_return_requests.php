<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('exchange_order_id')->nullable()->after('outcome_reference')
                ->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exchange_order_id');
        });
    }
};
