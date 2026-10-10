<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = ['customer_id', 'seller_id', 'reseller_id', 'order_id', 'category', 'subject', 'status', 'resolved_at'];
    protected function casts(): array { return ['resolved_at' => 'datetime']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function seller(): BelongsTo { return $this->belongsTo(Seller::class); }
    public function reseller(): BelongsTo { return $this->belongsTo(Reseller::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function messages(): HasMany { return $this->hasMany(SupportMessage::class)->orderBy('created_at')->orderBy('id'); }
}
