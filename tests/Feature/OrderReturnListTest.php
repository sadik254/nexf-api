<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderReturnListTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_return_list_contains_only_returning_lines(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'returns-buyer@example.test', 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'returns-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $seller = Seller::create([
            'seller_name' => 'Seller', 'email' => 'returns-seller@example.test', 'store_name' => 'Return Store',
            'store_slug' => 'return-store', 'kyc_type' => 'nid', 'kyc_number' => '123',
            'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'Clothing',
            'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $order = Order::create([
            'order_number' => 'RET-1', 'customer_id' => $customer->id, 'status' => 'return_pending',
            'payment_status' => 'paid', 'subtotal' => 100, 'total' => 100, 'shipping_charge' => 0,
            'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka',
        ]);
        foreach (['return_pending', 'returned', 'delivered'] as $status) {
            OrderItem::create([
                'order_id' => $order->id, 'seller_id' => $seller->id,
                'product_name' => $status, 'quantity' => 1, 'unit_selling_price' => 100,
                'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50,
                'line_profit' => 50, 'fulfillment_status' => $status,
            ]);
        }
        $adminToken = $admin->createToken('test', ['admin:orders'])->plainTextToken;
        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($adminToken)->getJson('/api/admin/returns')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.seller.store_name', 'Return Store')
            ->assertJsonPath('data.0.order.order_number', 'RET-1');
        $this->withToken($adminToken)->getJson('/api/admin/returns?status=return_pending')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.fulfillment_status', 'return_pending');
        $this->withToken($sellerToken)->getJson('/api/admin/returns')->assertForbidden();
        $this->withToken($sellerToken)->getJson('/api/seller/orders?status=return_pending&search=RET-1')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('status_counts.return_pending', 1);
        $this->withToken($sellerToken)->getJson('/api/seller/orders?status=delivered&search=RET-1')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('status_counts.return_pending', 1);
        $this->withToken($admin->createToken('people', ['admin:basic', 'admin:customers'])->plainTextToken)
            ->getJson('/api/admin/customers')->assertOk()
            ->assertJsonPath('data.0.orders_count', 1)
            ->assertJsonPath('data.0.received_orders_count', 0)
            ->assertJsonPath('data.0.total_spent', 100);
        $this->withToken($admin->createToken('sellers', ['admin:basic'])->plainTextToken)
            ->getJson('/api/admin/sellers')->assertOk()
            ->assertJsonPath('data.0.products_count', 0)
            ->assertJsonPath('data.0.units_sold', 3)
            ->assertJsonPath('data.0.revenue', 300);
    }
}
