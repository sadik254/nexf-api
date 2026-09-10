<?php
namespace App\Services;

use App\Mail\OrderNotificationMail;
use App\Models\Admin;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderNotificationService
{
    public function placed(Order $order): void { $this->send($order, 'Order received', 'We received your order.'); $this->notifyOperations($order, 'New order received', 'A new order requires fulfilment.'); }
    public function statusChanged(Order $order): void { $this->send($order, 'Order status updated', "Your order is now {$order->status}."); }
    public function cancelled(Order $order): void { $this->send($order, 'Order cancelled', 'Your order has been cancelled.'); }
    private function notifyOperations(Order $order, string $subject, string $message): void {
        foreach ($order->items->pluck('seller')->filter()->unique('id') as $seller) $this->sendTo($seller->email, $order, $subject, $message);
        foreach (Admin::query()->where('is_active', true)->pluck('email') as $email) $this->sendTo($email, $order, $subject, $message);
    }
    private function send(Order $order, string $subject, string $message): void { $this->sendTo($order->customer->email, $order, $subject, $message); }
    private function sendTo(string $email, Order $order, string $subject, string $message): void { try { Mail::to($email)->send(new OrderNotificationMail($order, $subject, $message)); } catch (\Throwable $e) { Log::warning('Order notification failed', ['order_id' => $order->id, 'email' => $email, 'error' => $e->getMessage()]); } }
}
