<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ConsoleNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerManualPaymentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_orders_are_released_to_a_store_only_after_payment_is_received(): void
    {
        $seller = Seller::create(['seller_name' => 'Store owner', 'store_name' => 'Store', 'store_slug' => 'store', 'email' => 'store@example.test', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $order = Order::create(['order_number' => 'MANUAL-1', 'status' => 'pending', 'payment_method_code' => 'bkash', 'payment_method_name' => 'bKash', 'subtotal' => 100, 'total' => 100, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);
        $item = OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Item', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'fulfillment_status' => 'pending']);

        $sellerToken = $seller->createToken('seller', ['seller'])->plainTextToken;
        $this->withToken($sellerToken)->getJson('/api/seller/orders')->assertOk()->assertJsonPath('total', 0);
        $this->withToken($sellerToken)->getJson("/api/seller/orders/{$order->id}")->assertNotFound();
        $this->withToken($sellerToken)->postJson("/api/seller/orders/{$order->id}/items/{$item->id}/fulfillment", ['status' => 'confirmed'])->assertNotFound();
        $this->assertDatabaseCount('console_notifications', 0);

        $adminToken = $admin->createToken('admin', ['admin'])->plainTextToken;
        $this->withToken($adminToken)->postJson("/api/admin/orders/{$order->id}", ['paid_amount' => 100])->assertOk();

        $this->withToken($sellerToken)->getJson('/api/seller/orders')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame(1, ConsoleNotification::query()->where('recipient_type', 'seller')->where('recipient_id', $seller->id)->where('type', 'order_new')->count());
    }
}
