<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerShippingRate extends Model
{
    protected $fillable = ['seller_id', 'shipping_method_id', 'charge'];
    protected function casts(): array { return ['charge' => 'decimal:2']; }
    public function seller(): BelongsTo { return $this->belongsTo(Seller::class); }
    public function shippingMethod(): BelongsTo { return $this->belongsTo(ShippingMethod::class); }
}
