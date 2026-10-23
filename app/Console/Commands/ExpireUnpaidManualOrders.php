<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\ManualPaymentExpiryService;
use Illuminate\Console\Command;

class ExpireUnpaidManualOrders extends Command
{
    protected $signature = 'orders:expire-unpaid-manual';
    protected $description = 'Cancel unpaid manual-transfer orders after their payment window expires';

    public function handle(ManualPaymentExpiryService $expiry): int
    {
        Order::query()
            ->where('status', 'pending')
            ->whereIn('payment_method_code', ManualPaymentExpiryService::METHODS)
            ->where(fn ($query) => $query->whereNull('paid_amount')->orWhere('paid_amount', '<=', 0))
            ->whereNull('payment_reference')->whereNull('payment_sender')
            ->where(fn ($query) => $query->where('placed_at', '<=', now()->subMinutes(30))->orWhere(fn ($fallback) => $fallback->whereNull('placed_at')->where('created_at', '<=', now()->subMinutes(30))))
            ->orderBy('id')->eachById(fn (Order $order) => $expiry->cancelIfExpired($order));

        return self::SUCCESS;
    }
}
