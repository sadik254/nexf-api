<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\Product;

class ProductVideoThumbnailService
{
    /**
     * Return saved frame times keyed by video URL. One query serves an entire
     * product list, keeping storefront and console payloads free of N+1 reads.
     *
     * @param iterable<Product> $products
     * @return array<string, float>
     */
    public function forProducts(iterable $products): array
    {
        $urls = collect($products)
            ->flatMap(fn (Product $product) => $product->videos ?? [])
            ->filter(fn ($url) => is_string($url) && $url !== '')
            ->unique()
            ->values();

        if ($urls->isEmpty()) return [];

        return MediaAsset::query()
            ->whereIn('url', $urls)
            ->where('mime_type', 'like', 'video/%')
            ->whereNotNull('thumbnail_time_seconds')
            ->pluck('thumbnail_time_seconds', 'url')
            ->map(fn ($time) => (float) $time)
            ->all();
    }
}
