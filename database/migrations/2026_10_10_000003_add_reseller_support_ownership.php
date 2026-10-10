<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreignId('reseller_id')->nullable()->after('seller_id')->constrained()->cascadeOnDelete();
            $table->index(['reseller_id', 'status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['reseller_id', 'status', 'updated_at']);
            $table->dropConstrainedForeignId('reseller_id');
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
        // Keep customer_id nullable so rolling back this additive feature cannot
        // destroy reseller tickets or make their rows impossible to retain.
    }
};
