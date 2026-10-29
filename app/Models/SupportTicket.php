<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = ['reference', 'customer_id', 'seller_id', 'reseller_id', 'order_id', 'category', 'subject', 'guest_name', 'guest_phone', 'guest_email', 'contact_ip', 'no_store', 'status', 'resolved_at', 'respond_by', 'seller_responded_at', 'escalated_at'];
    protected $appends = ['overdue'];
    protected function casts(): array { return ['no_store' => 'boolean', 'resolved_at' => 'datetime', 'respond_by' => 'datetime', 'seller_responded_at' => 'datetime', 'escalated_at' => 'datetime']; }
    /** A store ticket the store hasn't answered by its deadline - NEXF steps in. */
    public function getOverdueAttribute(): bool { return $this->seller_id !== null && $this->status === 'open' && $this->seller_responded_at === null && $this->respond_by !== null && $this->respond_by->isPast(); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function seller(): BelongsTo { return $this->belongsTo(Seller::class); }
    public function reseller(): BelongsTo { return $this->belongsTo(Reseller::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function messages(): HasMany { return $this->hasMany(SupportMessage::class)->orderBy('created_at')->orderBy('id'); }
}
