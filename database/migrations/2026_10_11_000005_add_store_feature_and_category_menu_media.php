<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false);
        });

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->text('image')->nullable();
            $table->string('menu_heading', 120)->nullable();
            $table->text('promo_image')->nullable();
            $table->text('promo_href')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropColumn(['image', 'menu_heading', 'promo_image', 'promo_href']);
        });
        Schema::table('sellers', function (Blueprint $table): void {
            $table->dropColumn('is_featured');
        });
    }
};
