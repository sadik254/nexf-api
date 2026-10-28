<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ConsoleNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Services\ConsoleNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsoleNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_store_bells_are_scoped_and_mutable(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'bell-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $seller = Seller::create(['seller_name' => 'Store owner', 'email' => 'bell-store@example.test', 'store_name' => 'Bell Store', 'store_slug' => 'bell-store', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        ConsoleNotification::create(['recipient_type' => 'admin', 'recipient_id' => $admin->id, 'type' => 'payment_submitted', 'title' => 'Payment waiting for verification', 'body' => 'Review it.', 'data' => ['url' => '/dashboard/payments']]);
        ConsoleNotification::create(['recipient_type' => 'seller', 'recipient_id' => $seller->id, 'type' => 'order_new', 'title' => 'New order NEXF-00001', 'data' => ['order_number' => 'NEXF-00001']]);

        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/notifications')
            ->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.data.0.data.url', '/dashboard/payments');
        $this->withToken($seller->createToken('test', ['seller'])->plainTextToken)->getJson('/api/seller/notifications')
            ->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.data.0.data.order_number', 'NEXF-00001');

        $this->withToken($seller->createToken('test-2', ['seller'])->plainTextToken)->postJson('/api/seller/notifications/read-all')->assertOk();
        $this->assertSame(0, ConsoleNotification::where('recipient_type', 'seller')->whereNull('read_at')->count());
        $this->assertSame(1, ConsoleNotification::where('recipient_type', 'admin')->whereNull('read_at')->count());
    }

    public function test_new_order_notifies_admins_and_its_store(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'order-bell-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $seller = Seller::create(['seller_name' => 'Store owner', 'email' => 'order-bell-store@example.test', 'store_name' => 'Bell Store', 'store_slug' => 'order-bell-store', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $order = Order::create(['order_number' => 'NEXF-00022', 'status' => 'pending', 'payment_status' => 'unpaid', 'subtotal' => 100, 'total' => 100, 'shipping_name' => 'Buyer', 'shipping_phone' => '01712345678', 'shipping_address' => 'Dhaka']);
        OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Item', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50]);

        app(ConsoleNotificationService::class)->orderPlaced($order);

        $this->assertDatabaseHas('console_notifications', ['recipient_type' => 'admin', 'recipient_id' => $admin->id, 'type' => 'order_new']);
        $this->assertDatabaseHas('console_notifications', ['recipient_type' => 'seller', 'recipient_id' => $seller->id, 'type' => 'order_new']);
    }
}
