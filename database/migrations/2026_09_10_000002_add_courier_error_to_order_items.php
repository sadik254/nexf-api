<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('order_items', 'courier_error')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->text('courier_error')->nullable()->after('courier_updated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'courier_error')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('courier_error');
            });
        }
    }
};
