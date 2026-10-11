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
    public function placed(Order $order): void { $this->notifyCustomer($order, 'order_placed', 'Order received', 'We received your order.'); $this->send($order, 'Order received', 'We received your order.'); $this->notifyOperations($order, 'New order received', 'A new order requires fulfilment.'); }
    public function statusChanged(Order $order): void { $this->notifyCustomer($order, 'order_status', 'Order status updated', "Your order is now {$order->status}."); $this->send($order, 'Order status updated', "Your order is now {$order->status}."); }
    public function cancelled(Order $order): void { $this->notifyCustomer($order, 'order_cancelled', 'Order cancelled', 'Your order has been cancelled.'); $this->send($order, 'Order cancelled', 'Your order has been cancelled.'); }
    private function notifyCustomer(Order $order, string $type, string $title, string $body): void
    {
        if (!$order->customer_id) return;
        CustomerInSiteNotification::create([
            'customer_id' => $order->customer_id, 'type' => $type, 'title' => $title, 'body' => $body,
            'data' => ['order_id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status],
        ]);
    }
    private function notifyOperations(Order $order, string $subject, string $message): void {
        foreach ($order->items->pluck('seller')->filter()->unique('id') as $seller) $this->sendTo($seller->email, $order, $subject, $message);
        foreach (Admin::query()->where('is_active', true)->pluck('email') as $email) $this->sendTo($email, $order, $subject, $message);
    }
    private function send(Order $order, string $subject, string $message): void { $email = $order->shipping_email ?: $order->customer?->email; if ($email) $this->sendTo($email, $order, $subject, $message); }
    private function sendTo(string $email, Order $order, string $subject, string $message): void { try { Mail::to($email)->send(new OrderNotificationMail($order, $subject, $message)); } catch (\Throwable $e) { Log::warning('Order notification failed', ['order_id' => $order->id, 'email' => $email, 'error' => $e->getMessage()]); } }
}
