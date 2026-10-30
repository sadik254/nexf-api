<?php

use App\Models\Admin;
use App\Models\Seller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('uploaded_by_type', 20)->nullable()->after('owner_id');
            $table->unsignedBigInteger('uploaded_by_id')->nullable()->after('uploaded_by_type');
            $table->string('uploader_name', 255)->nullable()->after('uploaded_by_id');
            $table->index(['uploaded_by_type', 'uploaded_by_id']);
        });

        Schema::create('media_asset_store_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->nullOnDelete();
            // `house` or `seller:{id}`. This gives the house library a real,
            // unique identity instead of relying on MySQL's nullable uniques.
            $table->string('library_key', 32);
            $table->timestamps();
            $table->unique(['media_asset_id', 'library_key']);
            $table->index('seller_id');
        });

        DB::table('media_assets')->orderBy('id')->chunkById(200, function ($assets): void {
            foreach ($assets as $asset) {
                $uploaderType = in_array($asset->owner_type, ['admin', 'seller'], true) ? $asset->owner_type : null;
                $uploaderName = match ($uploaderType) {
                    'admin' => Admin::query()->whereKey($asset->owner_id)->value('name'),
                    'seller' => Seller::query()->whereKey($asset->owner_id)->value('seller_name'),
                    default => null,
                };
                if ($uploaderType) {
                    DB::table('media_assets')->where('id', $asset->id)->update([
                        'uploaded_by_type' => $uploaderType,
                        'uploaded_by_id' => $asset->owner_id,
                        'uploader_name' => $uploaderName,
                    ]);
                }

                if ($asset->owner_type === 'seller' && Seller::query()->whereKey($asset->owner_id)->exists()) {
                    DB::table('media_asset_store_libraries')->insertOrIgnore([
                        'media_asset_id' => $asset->id,
                        'seller_id' => $asset->owner_id,
                        'library_key' => "seller:{$asset->owner_id}",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } elseif ($asset->owner_type === 'admin') {
                    DB::table('media_asset_store_libraries')->insertOrIgnore([
                        'media_asset_id' => $asset->id,
                        'seller_id' => null,
                        'library_key' => 'house',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_asset_store_libraries');
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropIndex(['uploaded_by_type', 'uploaded_by_id']);
            $table->dropColumn(['uploaded_by_type', 'uploaded_by_id', 'uploader_name']);
        });
    }
};
