<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductCollection extends Model
{
    protected $fillable = ['seller_id', 'name', 'description', 'photo_category_ids'];

    protected function casts(): array
    {
        return ['photo_category_ids' => 'array'];
    }

    public function seller(): BelongsTo { return $this->belongsTo(Seller::class); }
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'collection_product')->withPivot('sort_order')->orderByPivot('sort_order');
    }
}
