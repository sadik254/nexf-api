<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBundle extends Model
{
    protected $fillable = ['product_id', 'label', 'mode', 'size', 'price', 'compare_at_price', 'sort_order'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'price' => 'decimal:2', 'compare_at_price' => 'decimal:2', 'sort_order' => 'integer'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function items(): HasMany { return $this->hasMany(ProductBundleItem::class, 'bundle_id')->orderBy('id'); }
}
