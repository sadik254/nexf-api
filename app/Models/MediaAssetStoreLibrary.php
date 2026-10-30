<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAssetStoreLibrary extends Model
{
    protected $fillable = ['media_asset_id', 'seller_id', 'library_key'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
