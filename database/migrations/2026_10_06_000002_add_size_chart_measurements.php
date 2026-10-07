<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('size_charts', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->change();
            $table->string('unit', 2)->default('in');
            $table->string('audience', 10)->default('unisex');
            $table->string('category_slug')->nullable();
            $table->string('subcategory_slug')->nullable();
            $table->json('columns')->nullable();
            $table->json('rows')->nullable();
            $table->text('note')->nullable();
        });
    }
    public function down(): void {
        Schema::table('size_charts', function (Blueprint $table) {
            $table->dropColumn(['unit', 'audience', 'category_slug', 'subcategory_slug', 'columns', 'rows', 'note']);
        });
    }
};
