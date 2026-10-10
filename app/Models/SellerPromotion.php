<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPromotion extends Model
{
    protected $fillable = [
        'seller_id', 'title', 'body', 'code', 'discount_label', 'expires_at',
        'image_url', 'theme', 'pinned',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date:Y-m-d',
            'pinned' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
