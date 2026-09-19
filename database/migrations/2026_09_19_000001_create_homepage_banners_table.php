<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('homepage_banners', function (Blueprint $table) {
            $table->id();
            $table->string('placement'); // hero, side_top, side_bottom
            $table->string('image');
            $table->string('href')->default('/shop');
            $table->string('title')->nullable();
            $table->string('emphasis')->nullable();
            $table->string('subtitle')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['placement', 'is_active', 'sort_order']);
        });
    }

    public function down(): void { Schema::dropIfExists('homepage_banners'); }
};
