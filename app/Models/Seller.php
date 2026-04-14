<?php

namespace App\Models;

use Database\Factories\SellerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Seller extends Authenticatable
{
    /** @use HasFactory<SellerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
