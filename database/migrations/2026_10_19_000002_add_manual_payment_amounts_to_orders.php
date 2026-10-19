<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('payment_amount', 12, 2)->nullable()->after('payment_sender');
            $table->timestamp('payment_received_at')->nullable()->after('payment_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_amount', 'payment_received_at']);
        });
    }
};
