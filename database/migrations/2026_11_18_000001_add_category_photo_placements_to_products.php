<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('main_photos')->nullable()->after('gallery');
        });

        Schema::create('product_category_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->json('photos')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category_placements');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('main_photos');
        });
    }
};
