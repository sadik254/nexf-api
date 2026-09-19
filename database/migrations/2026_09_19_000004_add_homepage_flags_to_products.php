<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('homepage_trending')->default(false)->index();
            $table->boolean('homepage_new_arrival')->default(false)->index();
            $table->boolean('homepage_featured')->default(false)->index();
            $table->unsignedInteger('homepage_sort_order')->default(0);
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['homepage_trending','homepage_new_arrival','homepage_featured','homepage_sort_order']);
        });
    }
};
