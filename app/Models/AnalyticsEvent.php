<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    public const EVENTS = ['page_view', 'product_view', 'add_to_cart', 'checkout_started', 'order_completed'];
    public $timestamps = false;
    protected $fillable = ['session_id', 'visitor_id', 'seller_id', 'event', 'page', 'channel', 'referrer', 'device', 'location', 'ip', 'created_at'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
}
