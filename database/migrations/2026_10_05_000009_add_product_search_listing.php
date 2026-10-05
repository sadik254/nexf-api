<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('seo_title', 70)->nullable()->after('slug');
            $table->string('seo_description', 170)->nullable()->after('seo_title');
        });
    }
    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['seo_title', 'seo_description']));
    }
};
