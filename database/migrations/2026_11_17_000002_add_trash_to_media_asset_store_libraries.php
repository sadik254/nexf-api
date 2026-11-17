<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_asset_store_libraries', function (Blueprint $table): void {
            $table->string('deleted_by_name', 255)->nullable()->after('library_key');
            $table->softDeletes();
            $table->index(['seller_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media_asset_store_libraries', function (Blueprint $table): void {
            $table->dropIndex(['seller_id', 'deleted_at']);
            $table->dropSoftDeletes();
            $table->dropColumn('deleted_by_name');
        });
    }
};
