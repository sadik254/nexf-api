<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('paid_amount', 12, 2)->nullable()->after('total');
            $table->text('internal_note')->nullable()->after('notes');
            $table->boolean('is_guest')->default(false)->after('internal_note');
            $table->boolean('manual_ship')->default(false)->after('is_guest');
            $table->string('exchange_for')->nullable()->after('manual_ship');
        });
    }
    public function down(): void {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'internal_note', 'is_guest', 'manual_ship', 'exchange_for']);
        });
    }
};
