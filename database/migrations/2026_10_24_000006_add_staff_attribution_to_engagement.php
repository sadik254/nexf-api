<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_questions', fn (Blueprint $table) => $table->string('answered_by_name', 255)->nullable()->after('answered_by_id'));
        Schema::table('reviews', fn (Blueprint $table) => $table->string('seller_responded_by_name', 255)->nullable()->after('seller_responded_at'));
    }

    public function down(): void
    {
        Schema::table('product_questions', fn (Blueprint $table) => $table->dropColumn('answered_by_name'));
        Schema::table('reviews', fn (Blueprint $table) => $table->dropColumn('seller_responded_by_name'));
    }
};
