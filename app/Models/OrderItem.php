<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'variation_id',
        'seller_id',
        'product_name',
        'product_slug',
        'product_thumbnail',
        'product_gallery',
        'product_image_variants',
        'sku',
        'variation_attributes',
        'quantity',
        'unit_selling_price',
        'unit_buying_price',
        'line_subtotal',
        'line_cost',
        'line_profit',
        'lot_allocations',
        'fulfillment_status',
        'tracking_number',
        'courier_provider',
        'courier_consignment_id',
        'courier_invoice',
        'courier_status',
        'courier_created_at',
        'courier_updated_at',
        'courier_error',
        'shipped_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'variation_attributes' => 'array',
            'product_gallery' => 'array',
            'product_image_variants' => 'array',
            'quantity' => 'integer',
            'unit_selling_price' => 'decimal:2',
            'unit_buying_price' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'line_cost' => 'decimal:2',
            'line_profit' => 'decimal:2',
            'lot_allocations' => 'array',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'courier_created_at' => 'datetime',
            'courier_updated_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
