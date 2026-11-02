<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleItem extends Model
{
    protected $fillable = ['bundle_id', 'attributes', 'quantity'];
    protected function casts(): array { return ['attributes' => 'array', 'quantity' => 'integer']; }
    public function bundle(): BelongsTo { return $this->belongsTo(ProductBundle::class, 'bundle_id'); }
}
