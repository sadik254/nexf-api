<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_commission_is_zero_below_target_and_applies_to_delivered_attributed_sales(): void
    {
        $admin = Admin::create(['name' => 'Super', 'email' => 'reseller-admin@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $resellerId = $this->withToken($token)->postJson('/api/admin/resellers', ['name' => 'Partner', 'email' => 'partner@example.test', 'commission_rate' => 10, 'monthly_target' => 3])->assertCreated()->json('id');
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'reseller-buyer@example.test', 'password' => 'password123', 'reseller_id' => $resellerId]);
        $order = Order::create(['order_number' => 'RES-1', 'customer_id' => $customer->id, 'reseller_id' => $resellerId, 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 220, 'total' => 220, 'shipping_charge' => 0, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);
        OrderItem::create(['order_id' => $order->id, 'product_name' => 'Shirt', 'quantity' => 2, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 200, 'line_cost' => 100, 'line_profit' => 100, 'fulfillment_status' => 'delivered']);
        $this->withToken($token)->getJson('/api/admin/commissions')->assertOk()->assertJsonPath('resellers.0.units_this_month', 2)->assertJsonPath('resellers.0.commission_earned', 0);
        OrderItem::create(['order_id' => $order->id, 'product_name' => 'Socks', 'quantity' => 1, 'unit_selling_price' => 20, 'unit_buying_price' => 10, 'line_subtotal' => 20, 'line_cost' => 10, 'line_profit' => 10, 'fulfillment_status' => 'delivered']);
        $this->withToken($token)->getJson('/api/admin/commissions')->assertOk()->assertJsonPath('resellers.0.units_this_month', 3)->assertJsonPath('resellers.0.commission_earned', 22);
    }
}
