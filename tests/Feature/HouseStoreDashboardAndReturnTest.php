<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseStoreDashboardAndReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_house_dashboard_uses_only_house_order_lines(): void
    {
        $admin = Admin::create(['name' => 'Super', 'email' => 'house-dashboard@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'house-dashboard-seller@example.test', 'store_name' => 'Seller store', 'store_slug' => 'house-dashboard-seller', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'General', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $order = $this->order('HOUSE-DASH');
        OrderItem::create(['order_id' => $order->id, 'product_name' => 'House item', 'quantity' => 2, 'unit_selling_price' => 50, 'unit_buying_price' => 20, 'line_subtotal' => 100, 'line_cost' => 40, 'line_profit' => 60, 'fulfillment_status' => 'delivered']);
        OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Seller item', 'quantity' => 3, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 300, 'line_cost' => 150, 'line_profit' => 150, 'fulfillment_status' => 'delivered']);

        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/dashboard?store=house')
            ->assertOk()->assertJsonPath('stats.revenue', 100)->assertJsonPath('stats.units_sold', 2);
    }

    public function test_a_line_gets_one_active_return_and_completion_resolves_its_ticket(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'return-one@example.test', 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Super', 'email' => 'return-one-admin@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $order = $this->order('RETURN-ONE', $customer->id);
        $item = OrderItem::create(['order_id' => $order->id, 'product_name' => 'Item', 'quantity' => 2, 'unit_selling_price' => 50, 'unit_buying_price' => 20, 'line_subtotal' => 100, 'line_cost' => 40, 'line_profit' => 60, 'fulfillment_status' => 'delivered']);
        $ticket = SupportTicket::create(['customer_id' => $customer->id, 'order_id' => $order->id, 'category' => 'Return', 'subject' => 'Return request', 'status' => 'open', 'reference' => 'SUP-RETURN']);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $returnId = $this->withToken($token)->postJson('/api/customers/return-requests', ['order_item_id' => $item->id, 'support_ticket_id' => $ticket->id, 'type' => 'refund', 'quantity' => 1, 'reason' => 'Damaged'])->assertCreated()->json('id');
        $this->withToken($token)->postJson('/api/customers/return-requests', ['order_item_id' => $item->id, 'type' => 'refund', 'quantity' => 1, 'reason' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('order_item_id');
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson("/api/admin/return-requests/{$returnId}", ['status' => 'approved'])->assertOk();
        $this->withToken($admin->createToken('test-2', ['admin'])->plainTextToken)->postJson("/api/admin/return-requests/{$returnId}", ['status' => 'received'])->assertOk();
        $this->withToken($admin->createToken('test-3', ['admin'])->plainTextToken)->postJson("/api/admin/return-requests/{$returnId}", ['status' => 'refunded', 'refund_amount' => 50])->assertOk();
        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    private function order(string $number, ?int $customerId = null): Order
    {
        return Order::create(['order_number' => $number, 'customer_id' => $customerId, 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 100, 'total' => 100, 'shipping_charge' => 0, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);
    }
}
