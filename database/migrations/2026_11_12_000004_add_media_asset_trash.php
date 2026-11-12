<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('deleted_by_name', 255)->nullable()->after('alt_text');
            $table->softDeletes();
            $table->index('deleted_at');
        });
        // A trashed asset must be detachable immediately, including from rows
        // whose editor normally requires an image.
        Schema::table('homepage_banners', fn (Blueprint $table) => $table->string('image')->nullable()->change());
        Schema::table('homepage_offer_blocks', fn (Blueprint $table) => $table->string('image', 1000)->nullable()->change());
        Schema::table('homepage_category_cards', fn (Blueprint $table) => $table->string('image', 1000)->nullable()->change());
        Schema::table('homepage_clients', fn (Blueprint $table) => $table->string('logo', 2048)->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
            $table->dropColumn('deleted_by_name');
        });
        Schema::table('homepage_banners', fn (Blueprint $table) => $table->string('image')->nullable(false)->change());
        Schema::table('homepage_offer_blocks', fn (Blueprint $table) => $table->string('image', 1000)->nullable(false)->change());
        Schema::table('homepage_category_cards', fn (Blueprint $table) => $table->string('image', 1000)->nullable(false)->change());
        Schema::table('homepage_clients', fn (Blueprint $table) => $table->string('logo', 2048)->nullable(false)->change());
    }
};
