<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('specification_tables')->nullable()->after('specifications');
            $table->json('videos')->nullable()->after('gallery');
            $table->decimal('weight_kg', 8, 2)->nullable()->after('default_selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['specification_tables', 'videos', 'weight_kg']));
    }
};
