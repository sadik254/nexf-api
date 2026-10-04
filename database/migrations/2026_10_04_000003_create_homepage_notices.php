<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('homepage_notices', function (Blueprint $table) {
            $table->id();
            $table->text('text');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['enabled', 'sort_order']);
        });
    }

    public function down(): void { Schema::dropIfExists('homepage_notices'); }
};
