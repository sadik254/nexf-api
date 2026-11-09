<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('logo', 2048);
            $table->string('url', 500)->nullable();
            $table->string('industry', 80)->nullable();
            $table->boolean('hidden')->default(false);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->index(['hidden', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_clients');
    }
};
