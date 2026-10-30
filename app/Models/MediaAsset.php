<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'uploaded_by_type', 'uploaded_by_id', 'uploader_name', 'source', 'url', 'file_name', 'mime_type', 'size_bytes', 'width', 'height', 'duration_seconds', 'alt_text'];
    protected function casts(): array { return ['size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'duration_seconds' => 'integer']; }

    public function storeLibraries(): HasMany
    {
        return $this->hasMany(MediaAssetStoreLibrary::class);
    }

    public function isInSellerLibrary(int $sellerId): bool
    {
        if ($this->storeLibraries()->where('seller_id', $sellerId)->exists()) return true;
        // Compatibility for assets created before the migration has run.
        return ! $this->storeLibraries()->exists() && $this->owner_type === 'seller' && (int) $this->owner_id === $sellerId;
    }

    public function isInHouseLibrary(): bool
    {
        if ($this->storeLibraries()->where('library_key', 'house')->exists()) return true;
        return ! $this->storeLibraries()->exists() && $this->owner_type === 'admin';
    }
}
