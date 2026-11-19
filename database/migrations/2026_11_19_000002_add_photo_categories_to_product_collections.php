<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_collections', function (Blueprint $table): void {
            $table->json('photo_category_ids')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('product_collections', function (Blueprint $table): void {
            $table->dropColumn('photo_category_ids');
        });
    }
};
