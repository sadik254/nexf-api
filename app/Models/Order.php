<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use SoftDeletes;
    public const MANUAL_PAYMENT_CODES = ['bkash', 'nagad', 'rocket', 'upay', 'bank'];
    protected $with = ['couponRedemptions'];
    protected $appends = ['applied_discounts', 'payable'];
    protected $hidden = ['couponRedemptions'];
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
        'wallet_amount',
        'wallet_reward_amount',
        'wallet_withdrawable_amount',
        'payment_reference',
        'payment_sender',
        'payment_amount',
        'payment_submitted_at',
        'payment_received_at',
        'payment_rejected_at',
        'shipping_name',
        'shipping_phone',
        'shipping_email',
        'ip_address',
        'shipping_address',
        'shipping_area',
        'shipping_district',
        'device_id',
        'notes',
        'internal_note',
        'is_guest',
        'staff_order',
        'manual_ship',
        'stock_request',
        'exchange_for',
        'placed_at',
        'packed_at',
        'payment_paid_at',
        'cancelled_at',
        'cancelled_by',
        'reseller_commission_rate',
        'reseller_monthly_target',
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
            'wallet_amount' => 'decimal:2',
            'wallet_reward_amount' => 'decimal:2',
            'wallet_withdrawable_amount' => 'decimal:2',
            'payment_amount' => 'decimal:2',
            'is_guest' => 'boolean',
            'staff_order' => 'boolean',
            'manual_ship' => 'boolean',
            'stock_request' => 'boolean',
            'placed_at' => 'datetime',
            'packed_at' => 'datetime',
            'payment_paid_at' => 'datetime',
            'payment_submitted_at' => 'datetime',
            'payment_received_at' => 'datetime',
            'payment_rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reseller_commission_rate' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Order $order): void {
            if ($order->isDirty('payment_status') && $order->payment_status === 'paid' && !$order->payment_paid_at) {
                $order->payment_paid_at = now();
            }
            if ($order->isDirty('paid_amount') && in_array($order->payment_method_code, ['bkash', 'nagad', 'rocket', 'upay', 'bank'], true)) {
                $order->payment_received_at = (float) $order->paid_amount > 0 ? ($order->payment_received_at ?? now()) : null;
            }
        });
        // Money in or out changes what each unshipped parcel collects on delivery.
        static::saved(function (Order $order): void {
            if ($order->wasChanged(['paid_amount', 'payment_status', 'wallet_amount'])) app(\App\Services\SubOrderService::class)->allocate($order);
        });
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

    /** Manual transfers stay private to NEXF until payment is received. */
    public function scopeVisibleToSeller($query)
    {
        return $query->where(function ($orders): void {
            $orders->whereNotIn('payment_method_code', self::MANUAL_PAYMENT_CODES)
                ->orWhere('paid_amount', '>', 0)
                ->orWhereHas('items', fn ($items) => $items->whereIn('fulfillment_status', ['confirmed', 'packed', 'shipped', 'delivered', 'return_pending', 'returned']));
        });
    }

    public function isHeldFromSeller(): bool
    {
        return in_array($this->payment_method_code, self::MANUAL_PAYMENT_CODES, true)
            && (float) ($this->paid_amount ?? 0) <= 0
            && ! $this->items()->whereIn('fulfillment_status', ['confirmed', 'packed', 'shipped', 'delivered', 'return_pending', 'returned'])->exists();
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

    public function couponRedemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    protected function appliedDiscounts(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(fn () => $this->couponRedemptions->map(fn (CouponRedemption $redemption) => [
            'name' => $redemption->coupon_name ?: $redemption->coupon_code,
            'code' => $redemption->coupon_code,
            'kind' => $redemption->is_automatic ? 'automatic' : 'coupon',
            'amount' => (float) $redemption->discount_amount,
        ])->values()->all());
    }

    protected function payable(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(fn () => round(max(0, (float) $this->total - (float) ($this->wallet_amount ?? 0)), 2));
    }
}
