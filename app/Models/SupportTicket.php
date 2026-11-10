<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    protected $fillable = ['reference', 'reply_token', 'customer_id', 'seller_id', 'reseller_id', 'order_id', 'category', 'subject', 'guest_name', 'guest_phone', 'guest_email', 'contact_ip', 'no_store', 'status', 'store_read_at', 'resolved_at', 'respond_by', 'seller_responded_at', 'escalated_at'];

    protected $hidden = ['reply_token'];

    protected $appends = ['overdue'];

    protected function casts(): array
    {
        return ['no_store' => 'boolean', 'store_read_at' => 'datetime', 'resolved_at' => 'datetime', 'respond_by' => 'datetime', 'seller_responded_at' => 'datetime', 'escalated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            $ticket->reply_token ??= self::newReplyToken();
        });
    }

    /** A store ticket the store hasn't answered by its deadline - NEXF steps in. */
    public function getOverdueAttribute(): bool
    {
        return $this->seller_id !== null && $this->status === 'open' && $this->seller_responded_at === null && $this->respond_by !== null && $this->respond_by->isPast();
    }

    public function ensureReplyToken(): string
    {
        if (! $this->reply_token) {
            do {
                $token = self::newReplyToken();
            } while (self::query()->where('reply_token', $token)->exists());
            $this->forceFill(['reply_token' => $token])->save();
        }

return $this->reply_token;
    }

    private static function newReplyToken(): string
    {
        return strtolower(Str::random(32));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('created_at')->orderBy('id');
    }
}
