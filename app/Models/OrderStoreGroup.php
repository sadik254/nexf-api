<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One store's sub-order inside a customer order: the lines one parcel carries.
 * `ships_by` is "nexf" for the house store (booked with Steadfast by NEXF) and
 * "seller" for a third-party store, which ships it with its own courier and
 * keeps the COD it collects.
 */
class OrderStoreGroup extends Model
{
    protected $fillable = [
        'order_id',
        'number',
        'seller_id',
        'ships_by',
        'status',
        'shipping_method_id',
        'store_name',
        'subtotal',
        'shipping_method_code',
        'shipping_method_name',
        'shipping_charge',
        'shipping_currency',
        'discount_share',
        'seller_funded_discount',
        'manual_discount_kind',
        'manual_discount_value',
        'manual_discount',
        'prepaid_amount',
        'cod_amount',
        'courier_provider',
        'tracking_number',
        'courier_tracking_url',
        'courier_consignment_id',
        'courier_invoice',
        'courier_status',
        'courier_error',
        'packed_at',
        'shipped_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_charge' => 'decimal:2',
            'discount_share' => 'decimal:2',
            'seller_funded_discount' => 'decimal:2',
            'manual_discount_value' => 'decimal:2',
            'manual_discount' => 'decimal:2',
            'prepaid_amount' => 'decimal:2',
            'cod_amount' => 'decimal:2',
            'packed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'store_group_id');
    }

    /** What the customer owes for this sub-order before any prepayment. */
    public function total(): float
    {
        return round((float) $this->subtotal + (float) $this->shipping_charge - (float) $this->discount_share, 2);
    }
}
