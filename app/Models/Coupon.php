<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'created_by_admin_id',
        'created_by_seller_id',
        'seller_id',
        'code',
        'is_automatic',
        'discount_kind', 'combines',
        'name',
        'description',
        'discount_type',
        'applies_to',
        'discount_value',
        'minimum_order_amount',
        'minimum_quantity',
        'eligible_product_ids',
        'eligible_category_ids',
        'eligible_collection_ids', 'buy_product_ids', 'buy_category_ids', 'buy_collection_ids', 'buy_quantity', 'get_quantity', 'uses_per_order', 'reward_type',
        'maximum_discount_amount',
        'usage_limit',
        'used_count',
        'per_customer_limit',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'minimum_quantity' => 'integer',
            'eligible_product_ids' => 'array',
            'eligible_category_ids' => 'array',
            'eligible_collection_ids' => 'array', 'buy_product_ids' => 'array', 'buy_category_ids' => 'array', 'buy_collection_ids' => 'array', 'buy_quantity' => 'integer', 'get_quantity' => 'integer', 'uses_per_order' => 'integer', 'combines' => 'boolean',
            'is_automatic' => 'boolean',
            'maximum_discount_amount' => 'decimal:2',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'per_customer_limit' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function sellerCreator(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'created_by_seller_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isUsable(): bool
    {
        return $this->unusableReason() === null;
    }

    public function unusableReason(): ?string
    {
        if (!$this->is_active) {
            return 'Coupon is inactive.';
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return 'Coupon is not active yet.';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'Coupon has expired.';
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return 'Coupon usage limit has been reached.';
        }

        return null;
    }

    public function discountForSubtotal(float $subtotal): float
    {
        if ($this->discount_kind === 'shipping') {
            return round($subtotal, 2);
        }
        if ($this->minimum_order_amount !== null && $subtotal < (float) $this->minimum_order_amount) {
            return 0.0;
        }

        $discount = $this->discount_type === 'percentage'
            ? round($subtotal * ((float) $this->discount_value / 100), 2)
            : (float) $this->discount_value;

        if ($this->maximum_discount_amount !== null) {
            $discount = min($discount, (float) $this->maximum_discount_amount);
        }

        return round(min($discount, $subtotal), 2);
    }
}
