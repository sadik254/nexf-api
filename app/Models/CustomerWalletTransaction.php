<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerWalletTransaction extends Model
{
    protected $fillable = ['customer_id', 'return_request_id', 'type', 'amount', 'description'];
    protected function casts(): array { return ['amount' => 'decimal:2']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function returnRequest(): BelongsTo { return $this->belongsTo(ReturnRequest::class); }
}
