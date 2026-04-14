<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'seller_id',
        'created_by_admin_id',
        'category_id',
        'name',
        'slug',
        'description',
        'product_type',
        'status',
        'thumbnail',
        'gallery',
        'default_buying_price',
        'default_selling_price',
    ];

    protected function casts(): array
    {
        return [
            'gallery' => 'array',
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
}
