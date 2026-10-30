<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_details_return_platform_wide_customer_history_without_exchange_orders(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'history@example.test', 'phone' => '01700000000', 'password' => 'password123']);
        $seller = Seller::create(['seller_name' => 'Store', 'store_name' => 'Store', 'store_slug' => 'history-store', 'email' => 'store-history@example.test', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'history-admin@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $subject = $this->order('HISTORY-1', $customer->id, '01700000000', 'pending');
        OrderItem::create(['order_id' => $subject->id, 'seller_id' => $seller->id, 'product_name' => 'Item', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'fulfillment_status' => 'pending']);
        $this->order('HISTORY-2', $customer->id, '01800000000', 'delivered');
        $this->order('HISTORY-3', null, '+8801700000000', 'cancelled');
        $this->order('HISTORY-X', $customer->id, '01700000000', 'delivered', 'EXCHANGE-1');

        $expected = ['orders' => 3, 'received' => 1, 'failed' => 1, 'pending' => 1];
        $adminToken = $admin->createToken('admin', ['admin'])->plainTextToken;
        $this->withToken($adminToken)->getJson("/api/admin/orders/{$subject->id}")->assertOk()->assertJsonPath('customer_history', $expected);
        $sellerToken = $seller->createToken('seller', ['seller'])->plainTextToken;
        $this->withToken($sellerToken)->getJson("/api/seller/orders/{$subject->id}")->assertOk()->assertJsonPath('customer_history', $expected);
    }

    private function order(string $number, ?int $customerId, string $phone, string $status, ?string $exchangeFor = null): Order
    {
        return Order::create(['order_number' => $number, 'customer_id' => $customerId, 'exchange_for' => $exchangeFor, 'status' => $status, 'subtotal' => 100, 'total' => 100, 'shipping_name' => 'Buyer', 'shipping_phone' => $phone, 'shipping_address' => 'Dhaka']);
    }
}
