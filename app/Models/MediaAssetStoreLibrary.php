<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAssetStoreLibrary extends Model
{
    use SoftDeletes;

    protected $fillable = ['media_asset_id', 'seller_id', 'library_key', 'deleted_by_name'];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
