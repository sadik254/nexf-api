<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration { public function up(): void { Schema::table('sellers', fn (Blueprint $table) => $table->json('store_layout')->nullable()); } public function down(): void { Schema::table('sellers', fn (Blueprint $table) => $table->dropColumn('store_layout')); } };
