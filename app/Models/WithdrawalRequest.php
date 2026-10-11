<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequest extends Model
{
    protected $fillable = ['reference', 'transaction_id', 'customer_id', 'amount', 'method', 'account_details', 'status', 'admin_note', 'processed_by_admin_id', 'processed_at'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'processed_at' => 'datetime']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function processedBy(): BelongsTo { return $this->belongsTo(Admin::class, 'processed_by_admin_id'); }
}
