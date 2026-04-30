<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLot extends Model
{
    protected $fillable = [
        'product_id',
        'variation_id',
        'lot_number',
        'buying_price',
        'selling_price',
        'quantity',
        'quantity_remaining',
        'received_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}
