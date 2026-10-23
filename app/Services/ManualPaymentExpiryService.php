<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ManualPaymentExpiryService
{
    public const METHODS = ['bkash', 'nagad', 'rocket', 'upay', 'bank'];

    public function isExpired(Order $order, ?Carbon $now = null): bool
    {
        $now ??= now();
        return in_array($order->payment_method_code, self::METHODS, true)
            && $order->status === 'pending'
            && (float) ($order->paid_amount ?? 0) <= 0
            && blank($order->payment_reference)
            && blank($order->payment_sender)
            && ($order->placed_at ?? $order->created_at)?->lte($now->copy()->subMinutes(30));
    }

    /** Cancel only when the payment deadline is still unmet. */
    public function cancelIfExpired(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->with(['items', 'customer'])->lockForUpdate()->findOrFail($order->id);
            if (! $this->isExpired($locked)) return false;

            foreach ($locked->items as $item) {
                if (in_array($item->fulfillment_status, ['shipped', 'delivered', 'return_pending'], true)) return false;
                app(InventoryService::class)->restoreOrderItem($item, $locked->customer, 'manual_payment_timeout');
                $item->update(['fulfillment_status' => 'cancelled']);
            }
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return true;
        });
    }
}
