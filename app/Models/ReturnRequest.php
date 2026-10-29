<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequest extends Model
{
    protected $fillable = ['reference', 'submission_reference', 'order_item_id', 'order_id', 'customer_id', 'seller_id', 'support_ticket_id', 'type', 'status', 'quantity', 'reason', 'decision_note', 'staff_note', 'refund_to', 'attachments', 'return_courier', 'return_address_name', 'return_address_phone', 'return_address_email', 'return_address', 'return_address_area', 'return_address_district', 'return_tracking', 'outcome_reference', 'exchange_order_id', 'refund_amount', 'reviewed_at', 'received_at', 'completed_at', 'restocked_at', 'return_to_name', 'return_to_phone', 'return_to_address', 'respond_by', 'escalated_at'];
    protected $appends = ['overdue'];
    protected function casts(): array { return ['attachments' => 'array', 'reviewed_at' => 'datetime', 'received_at' => 'datetime', 'completed_at' => 'datetime', 'restocked_at' => 'datetime', 'refund_amount' => 'decimal:2', 'respond_by' => 'datetime', 'escalated_at' => 'datetime']; }
    /** A store's request still waiting on the store past its deadline - NEXF steps in. */
    public function getOverdueAttribute(): bool { return $this->seller_id !== null && $this->status === 'requested' && $this->respond_by !== null && $this->respond_by->isPast(); }
    public function item(): BelongsTo { return $this->belongsTo(OrderItem::class, 'order_item_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function exchangeOrder(): BelongsTo { return $this->belongsTo(Order::class, 'exchange_order_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function seller(): BelongsTo { return $this->belongsTo(Seller::class); }
    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
}
