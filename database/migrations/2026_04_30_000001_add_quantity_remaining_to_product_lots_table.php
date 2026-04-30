<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_lots', function (Blueprint $table) {
            $table->integer('quantity_remaining')->default(0)->after('quantity');
        });

        DB::table('product_lots')->update([
            'quantity_remaining' => DB::raw('quantity'),
        ]);
    }

    public function down(): void
    {
        Schema::table('product_lots', function (Blueprint $table) {
            $table->dropColumn('quantity_remaining');
        });
    }
};
