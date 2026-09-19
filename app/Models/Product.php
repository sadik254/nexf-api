<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $appends = [
        'image_variants',
    ];

    protected $fillable = [
        'seller_id',
        'created_by_admin_id',
        'category_id',
        'name',
        'slug',
        'description',
        'specifications',
        'product_type',
        'status',
        'thumbnail',
        'gallery',
        'default_buying_price',
        'default_selling_price',
        'compare_at_price', 'option_groups', 'size_chart_id',
        'homepage_trending', 'homepage_new_arrival', 'homepage_featured', 'homepage_sort_order',
    ];

    protected function casts(): array
    {
        return [
            'gallery' => 'array',
            'specifications' => 'array',
            'option_groups' => 'array',
            'compare_at_price' => 'decimal:2',
            'homepage_trending' => 'boolean',
            'homepage_new_arrival' => 'boolean',
            'homepage_featured' => 'boolean',
            'homepage_sort_order' => 'integer',
        ];
    }

    protected function imageVariants(): Attribute
    {
        return Attribute::get(fn () => [
            'thumbnail' => $this->uploadcareVariants($this->thumbnail),
            'gallery' => array_map(
                fn (string $url) => $this->uploadcareVariants($url),
                $this->gallery ?? []
            ),
        ]);
    }

    private function uploadcareVariants(?string $url): ?array
    {
        if (!$url || !str_starts_with($url, 'https://ucarecdn.com/')) {
            return null;
        }

        $baseUrl = rtrim((string) preg_replace('#/-/.*$#', '', $url), '/');

        return [
            'preview' => $url,
            'card' => "{$baseUrl}/-/scale_crop/750x1000/smart/-/format/auto/-/quality/smart",
            'pdp_defaults' => "{$baseUrl}/-/resize/x1000/-/format/auto/-/quality/smart/",
            'pdp_zoom' => "{$baseUrl}/-/resize/x2000/-/format/auto/-/quality/better/",
            'thumb' => "{$baseUrl}/-/scale_crop/256x256/smart/-/format/auto",
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(ProductLot::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews(): HasMany { return $this->hasMany(Review::class); }
    public function questions(): HasMany { return $this->hasMany(ProductQuestion::class); }
    public function sizeChart(): BelongsTo { return $this->belongsTo(SizeChart::class); }
}
