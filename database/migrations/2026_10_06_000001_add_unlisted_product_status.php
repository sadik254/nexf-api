<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
        });
    }

    public function down(): void
    {
        DB::table('products')->where('status', 'unlisted')->update(['status' => 'inactive']);
        Schema::table('products', function (Blueprint $table) {
            $table->enum('status', ['draft', 'active', 'inactive'])->default('draft')->change();
        });
    }
};
