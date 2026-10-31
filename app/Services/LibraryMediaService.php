<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\Seller;
use Illuminate\Validation\ValidationException;

/** Resolves an existing media-library asset without downloading it in a browser. */
class LibraryMediaService
{
    public function image(?int $id, ?string $url, ?Seller $seller = null, bool $houseOnly = false): ?string
    {
        $asset = $this->asset($id, $url);
        if (! $asset) return null;
        if (! str_starts_with((string) $asset->mime_type, 'image/')) {
            throw ValidationException::withMessages(['media' => ['Select an image from the media library.']]);
        }
        $this->scope($asset, $seller, $houseOnly);
        return $asset->url;
    }

    public function document(?int $id, ?string $url, ?Seller $seller = null): ?string
    {
        $asset = $this->asset($id, $url);
        if (! $asset) return null;
        if (! str_starts_with((string) $asset->mime_type, 'image/') && $asset->mime_type !== 'application/pdf') {
            throw ValidationException::withMessages(['media' => ['Select an image or PDF from the media library.']]);
        }
        $this->scope($asset, $seller);
        return $asset->url;
    }

    private function asset(?int $id, ?string $url): ?MediaAsset
    {
        if (!$id && !$url) return null;
        $asset = $id ? MediaAsset::query()->find($id) : MediaAsset::query()->where('url', $url)->first();
        if (! $asset || $asset->owner_type === 'customer') {
            throw ValidationException::withMessages(['media' => ['Select a valid media-library file.']]);
        }
        return $asset;
    }

    private function scope(MediaAsset $asset, ?Seller $seller, bool $houseOnly = false): void
    {
        if ($seller && ! $asset->isInSellerLibrary($seller->id)) {
            throw ValidationException::withMessages(['media' => ['Select a file from this store media library.']]);
        }
        if ($houseOnly && ! $asset->isInHouseLibrary()) {
            throw ValidationException::withMessages(['media' => ['Select a file from the NEXF Lifestyle media library.']]);
        }
    }
}
