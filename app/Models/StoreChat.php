<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreChat extends Model
{
    protected $fillable = ['customer_id', 'seller_id', 'store_key', 'customer_read_at', 'store_read_at'];
    protected function casts(): array { return ['customer_read_at' => 'datetime', 'store_read_at' => 'datetime']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function seller(): BelongsTo { return $this->belongsTo(Seller::class); }
    public function messages(): HasMany { return $this->hasMany(StoreChatMessage::class)->orderBy('created_at')->orderBy('id'); }
}
