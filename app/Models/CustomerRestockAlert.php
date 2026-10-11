<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRestockAlert extends Model
{
    protected $fillable = ['customer_id', 'product_id', 'variation_id', 'variation_key', 'notified_at'];
    protected function casts(): array { return ['notified_at' => 'datetime']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function variation(): BelongsTo { return $this->belongsTo(ProductVariation::class); }
}
