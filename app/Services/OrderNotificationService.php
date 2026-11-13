<?php
namespace App\Services;

use App\Mail\OrderNotificationMail;
use App\Models\Admin;
use App\Models\CustomerInSiteNotification;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderNotificationService
{
    public function placed(Order $order): void {
        if ($order->exchange_for) {
            $this->notifyCustomer($order, 'exchange_replacement', 'Replacement order created', "We're preparing the replacement for your exchange.");
            $this->send($order, "Your replacement order {$order->order_number}", "We're preparing the replacement for your exchange.", 'Your exchange is on its way');
        } else {
            $this->notifyCustomer($order, 'order_placed', 'Order received', 'We received your order.');
            $this->send($order, "We received your order {$order->order_number}", 'We received your order.', 'Thanks for your order');
        }
        $this->notifyOperations($order, "New order {$order->order_number}", 'A new order requires fulfilment.', 'You have a new order');
        app(ConsoleNotificationService::class)->orderPlaced($order);
    }
    public function statusChanged(Order $order): void {
        $copy = match ($order->status) {
            'confirmed' => ["Your order {$order->order_number} is confirmed", 'Order confirmed', "We're getting it ready.", 'Order confirmed', "We're getting it ready."],
            'packed' => ["Your order {$order->order_number} is packed", 'Packed and ready to ship', 'Your parcel is ready for the courier.', 'Order packed', 'Your parcel is ready for the courier.'],
            'shipped' => ["Your order {$order->order_number} is on its way", 'Your order has shipped', "It's with the courier now.", 'Order shipped', "It's with the courier now."],
            'delivered', 'completed' => ["Your order {$order->order_number} was delivered", 'Your order has arrived', 'We hope you enjoy it.', 'Order delivered', 'We hope you enjoy it.'],
            'return_pending', 'returning', 'in_transit' => ["Return started for order {$order->order_number}", 'Your return is in progress', 'We’ll keep you updated.', 'Return started', "We'll keep you updated."],
            'returned' => ["Return complete for order {$order->order_number}", 'Your return is complete', 'Your return has been received.', 'Return complete', 'Your return has been received.'],
            default => ["Order update for {$order->order_number}", 'Order update', "Your order is now {$order->status}.", 'Order update', "Your order is now {$order->status}."],
        };
        [$subject, $heading, $message, $bellTitle, $bellBody] = $copy;
        $this->notifyCustomer($order, 'order_status', $bellTitle, $bellBody);
        $this->send($order, $subject, $message, $heading);
    }
    public function cancelled(Order $order): void { $this->notifyCustomer($order, 'order_cancelled', 'Order cancelled', 'Your order has been cancelled.'); $this->send($order, "Your order {$order->order_number} was cancelled", 'Your order has been cancelled.', 'Order cancelled'); if ($order->cancelled_by === 'customer') app(ConsoleNotificationService::class)->orderCancelled($order); }
    public function manualPaymentReceived(Order $order): void { app(ConsoleNotificationService::class)->orderReleased($order); foreach ($order->items->pluck('seller')->filter()->unique('id') as $seller) $this->sendTo($seller->email, $order, "Payment received for order {$order->order_number}", 'The shopper payment was confirmed. You can now fulfil this order.', 'seller', $seller->id, 'Ready to fulfil'); }
    public function paymentRejected(Order $order): void { $this->notifyCustomer($order, 'payment_rejected', 'Payment rejected', 'We could not confirm your payment, so this order was cancelled.'); $this->send($order, "Payment issue with order {$order->order_number}", 'We could not confirm your payment, so this order was cancelled.', "We couldn't confirm your payment"); }
    private function notifyCustomer(Order $order, string $type, string $title, string $body): void
    {
        if (!$order->customer_id) return;
        CustomerInSiteNotification::create([
            'customer_id' => $order->customer_id, 'type' => $type, 'title' => $title, 'body' => $body,
            'data' => ['order_id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status],
        ]);
    }
    private function notifyOperations(Order $order, string $subject, string $message, ?string $heading = null): void {
        if (! $order->isHeldFromSeller()) foreach ($order->items->pluck('seller')->filter()->unique('id') as $seller) $this->sendTo($seller->email, $order, $subject, $message, 'seller', $seller->id, $heading);
        foreach (Admin::query()->where('is_active', true)->pluck('email') as $email) $this->sendTo($email, $order, $subject, $message, 'admin', null, $heading);
    }
    private function send(Order $order, string $subject, string $message, ?string $heading = null): void { $email = $order->shipping_email ?: $order->customer?->email; if ($email) $this->sendTo($email, $order, $subject, $message, 'customer', null, $heading); }
    private function sendTo(string $email, Order $order, string $subject, string $message, string $audience = 'customer', ?int $sellerId = null, ?string $heading = null): void { try { Mail::to($email)->send(new OrderNotificationMail($order, $subject, $message, $audience, $sellerId, $heading)); } catch (\Throwable $e) { Log::warning('Order notification failed', ['order_id' => $order->id, 'email' => $email, 'error' => $e->getMessage()]); } }
}
