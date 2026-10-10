<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'order_number',
        'customer_id',
        'reseller_id',
        'payment_method_id',
        'shipping_method_id',
        'coupon_id',
        'status',
        'payment_status',
        'payment_method_code',
        'payment_method_name',
        'shipping_method_code',
        'shipping_method_name',
        'shipping_charge',
        'shipping_currency',
        'coupon_code',
        'subtotal',
        'discount_total',
        'manual_discount_kind',
        'manual_discount_value',
        'total',
        'paid_amount',
        'shipping_name',
        'shipping_phone',
        'shipping_email',
        'ip_address',
        'shipping_address',
        'notes',
        'internal_note',
        'is_guest',
        'manual_ship',
        'exchange_for',
        'placed_at',
        'packed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_charge' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'manual_discount_value' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'is_guest' => 'boolean',
            'manual_ship' => 'boolean',
            'placed_at' => 'datetime',
            'packed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
    public function reseller(): BelongsTo { return $this->belongsTo(Reseller::class); }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function storeGroups(): HasMany
    {
        return $this->hasMany(OrderStoreGroup::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
