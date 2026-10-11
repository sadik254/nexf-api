<?php

namespace App\Models;

use Database\Factories\SellerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Seller extends Authenticatable
{
    /** @use HasFactory<SellerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'sku_prefix', 'commission_rate', 'address_line', 'address_area', 'address_district',
        'seller_name',
        'email',
        'phone',
        'store_name',
        'store_slug',
        'store_address',
        'store_logo',
        'store_image',
        'seller_image',
        'kyc_type',
        'kyc_number',
        'kyc_document_url',
        'product_category',
        'support_email',
        'support_phone',
        'city',
        'country',
        'status',
        'rejection_reason',
        'approved_by',
        'approved_at',
        'password',
        'email_verified_at',
        'login_ip',
        'last_login_at',
        'is_active',
        'on_time_shipping_percentage', 'positive_rating_percentage', 'chat_response_percentage', 'is_featured',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'delivery_options_customized' => 'boolean',
            'password' => 'hashed',
            'commission_rate' => 'decimal:2',
            'roster_archived_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'on_time_shipping_percentage' => 'integer', 'positive_rating_percentage' => 'integer', 'chat_response_percentage' => 'integer',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function storeChats(): HasMany
    {
        return $this->hasMany(StoreChat::class);
    }

    public function productReviews(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(Review::class, Product::class, 'seller_id', 'product_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(SellerPromotion::class);
    }
}
