<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLotMovement extends Model
{
    protected $fillable = [
        'product_lot_id',
        'quantity_change',
        'reason',
        'actor_type',
        'actor_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductLot::class, 'product_lot_id');
    }
}
