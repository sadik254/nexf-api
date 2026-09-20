<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStoreGroup extends Model
{
    protected $fillable = [
        'order_id',
        'seller_id',
        'store_name',
        'subtotal',
        'shipping_method_code',
        'shipping_method_name',
        'shipping_charge',
        'shipping_currency',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_charge' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
