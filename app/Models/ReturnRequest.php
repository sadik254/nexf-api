<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequest extends Model
{
    protected $fillable = ['order_item_id', 'order_id', 'customer_id', 'seller_id', 'support_ticket_id', 'type', 'status', 'quantity', 'reason', 'decision_note', 'return_tracking', 'outcome_reference', 'exchange_order_id', 'refund_amount', 'reviewed_at', 'received_at', 'completed_at', 'restocked_at'];
    protected function casts(): array { return ['reviewed_at' => 'datetime', 'received_at' => 'datetime', 'completed_at' => 'datetime', 'restocked_at' => 'datetime', 'refund_amount' => 'decimal:2']; }
    public function item(): BelongsTo { return $this->belongsTo(OrderItem::class, 'order_item_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function exchangeOrder(): BelongsTo { return $this->belongsTo(Order::class, 'exchange_order_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function seller(): BelongsTo { return $this->belongsTo(Seller::class); }
    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
}
