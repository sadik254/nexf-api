<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\ConsoleNotification;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\Seller;

class ConsoleNotificationService
{
    public function admins(string $type, string $title, ?string $body = null, array $data = [], ?string $source = null): void
    {
        Admin::query()->where('is_active', true)->get(['id'])->each(fn (Admin $admin) => $this->create('admin', $admin->id, $type, $title, $body, $data, $source));
    }

    public function seller(int $sellerId, string $type, string $title, ?string $body = null, array $data = [], ?string $source = null): void
    {
        $this->create('seller', $sellerId, $type, $title, $body, $data, $source);
    }

    public function reseller(int $resellerId, string $type, string $title, ?string $body = null, array $data = [], ?string $source = null): void
    {
        $this->create('reseller', $resellerId, $type, $title, $body, $data, $source);
    }

    public function orderPlaced(Order $order): void
    {
        $data = $this->orderData($order);
        $this->admins('order_new', 'New order '.$order->order_number, 'A new order needs review.', $data, "order:{$order->id}:new");
        $order->items()->whereNotNull('seller_id')->pluck('seller_id')->unique()->each(fn (int $sellerId) => $this->seller($sellerId, 'order_new', 'New order '.$order->order_number, 'A new order contains your products.', $data, "order:{$order->id}:new"));
    }

    public function orderCancelled(Order $order): void
    {
        $data = $this->orderData($order);
        $title = 'Order '.$order->order_number.' cancelled';
        $this->admins('order_cancelled', $title, 'The shopper cancelled this order.', $data, "order:{$order->id}:cancelled");
        $order->items()->whereNotNull('seller_id')->pluck('seller_id')->unique()->each(fn (int $sellerId) => $this->seller($sellerId, 'order_cancelled', $title, 'The shopper cancelled this order.', $data, "order:{$order->id}:cancelled"));
    }

    public function manualPayment(Order $order): void
    {
        $this->admins('payment_submitted', 'Payment waiting for verification', "{$order->order_number} has a submitted manual payment.", $this->orderData($order) + ['url' => '/dashboard/payments'], "order:{$order->id}:payment-submitted");
    }

    public function returnRequested(\App\Models\ReturnRequest $return): void
    {
        $data = ['url' => '/dashboard/returns', 'order_number' => $return->order?->order_number, 'return_reference' => $return->reference];
        $type = $return->type === 'exchange' ? 'exchange_requested' : 'return_requested';
        $title = ucfirst($return->type).' request '.$return->reference;
        $this->admins($type, $title, 'A customer submitted a request.', $data, "return:{$return->id}:requested");
        if ($return->seller_id) $this->seller($return->seller_id, $type, $title, 'A customer submitted a request.', $data, "return:{$return->id}:requested");
    }

    public function support(\App\Models\SupportTicket $ticket, string $event = 'new'): void
    {
        $data = ['url' => '/dashboard/support', 'ticket_reference' => $ticket->reference, 'order_number' => $ticket->order?->order_number];
        $title = $event === 'reply' ? 'New support reply' : 'New support ticket';
        $source = "ticket:{$ticket->id}:{$event}:".($ticket->updated_at?->timestamp ?? time());
        $this->admins('support_'.$event, $title, $ticket->subject, $data, $source);
        if ($ticket->seller_id) $this->seller($ticket->seller_id, 'support_'.$event, $title, $ticket->subject, $data, $source);
        if ($ticket->reseller_id) $this->reseller($ticket->reseller_id, 'support_'.$event, $title, $ticket->subject, $data, $source);
    }

    /** A customer asked NEXF to step into a store's return or ticket. */
    public function escalated(string $url, string $reference, ?string $orderNumber, ?int $sellerId): void
    {
        $data = ['url' => $url, 'reference' => $reference, 'order_number' => $orderNumber];
        $this->admins('escalated', "{$reference} needs NEXF", 'The customer asked NEXF to step in.', $data, "escalated:{$reference}");
        if ($sellerId) $this->seller($sellerId, 'escalated', "{$reference} escalated to NEXF", 'The customer asked NEXF to step in.', $data, "escalated:{$reference}");
    }

    public function payout(string $payee, int $id, string $reference, float $amount): void
    {
        $data = ['url' => '/dashboard/withdrawals', 'reference' => $reference];
        $this->admins('payout_requested', 'New payout request', "{$reference} requests ৳".number_format($amount, 2), $data, "payout:{$payee}:{$id}");
    }

    public function withdrawal(string $reference, float $amount): void
    {
        $this->admins('withdraw_requested', 'New withdrawal request', "{$reference} requests ৳".number_format($amount, 2), ['url' => '/dashboard/withdrawals', 'reference' => $reference], "withdrawal:{$reference}");
    }

    public function moderation(?int $sellerId, string $type, string $title, string $url): void
    {
        $this->admins($type, $title, 'A moderation action is waiting.', ['url' => $url], "{$type}:{$title}");
        if ($sellerId) $this->seller($sellerId, $type, $title, 'A moderation action is waiting.', ['url' => $url], "{$type}:{$title}");
    }

    public function lowStock(?int $sellerId, string $name, int $available, int $lotId): void
    {
        $title = 'Low stock: '.$name;
        $data = ['url' => '/dashboard/inventory', 'available_quantity' => $available];
        $this->admins('restock_low', $title, "{$available} left in stock.", $data, "low-stock:{$lotId}:{$available}");
        if ($sellerId) $this->seller($sellerId, 'restock_low', $title, "{$available} left in stock.", $data, "low-stock:{$lotId}:{$available}");
    }

    private function orderData(Order $order): array { return ['order_number' => $order->order_number, 'url' => '/dashboard/orders/'.$order->order_number]; }
    private function create(string $recipientType, int $recipientId, string $type, string $title, ?string $body, array $data, ?string $source): void
    {
        if ($source === null) { ConsoleNotification::create(['recipient_type' => $recipientType, 'recipient_id' => $recipientId, 'type' => $type, 'title' => $title, 'body' => $body, 'data' => $data]); return; }
        ConsoleNotification::updateOrCreate(['recipient_type' => $recipientType, 'recipient_id' => $recipientId, 'source_key' => $source], ['type' => $type, 'title' => $title, 'body' => $body, 'data' => $data]);
    }
}
