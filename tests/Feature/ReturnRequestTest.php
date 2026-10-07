<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_seller_and_admin_return_workflow_is_scoped(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'return-buyer@example.test', 'password' => 'password123']);
        $other = Customer::create(['name' => 'Other', 'email' => 'return-other@example.test', 'password' => 'password123']);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'return-seller@example.test', 'store_name' => 'Store', 'store_slug' => 'return-store', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'return-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $order = Order::create(['order_number' => 'RMA-1', 'customer_id' => $customer->id, 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 200, 'total' => 200, 'shipping_charge' => 0, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);
        $item = OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Coat', 'quantity' => 2, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 200, 'line_cost' => 100, 'line_profit' => 100, 'fulfillment_status' => 'delivered']);
        $customerToken = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $payload = ['order_item_id' => $item->id, 'type' => 'refund', 'quantity' => 1, 'reason' => 'Wrong size'];
        $id = $this->withToken($customerToken)->postJson('/api/customers/return-requests', $payload)->assertCreated()->assertJsonPath('status', 'requested')->json('id');
        $this->withToken($customerToken)->postJson('/api/customers/return-requests', $payload)->assertCreated();
        $this->withToken($customerToken)->postJson('/api/customers/return-requests', $payload)->assertUnprocessable();
        $this->withToken($other->createToken('test', ['customer:basic'])->plainTextToken)->getJson("/api/customers/return-requests/{$id}")->assertNotFound();
        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($sellerToken)->getJson('/api/seller/return-requests')->assertOk()->assertJsonPath('total', 2);
        $this->withToken($sellerToken)->postJson("/api/seller/return-requests/{$id}", ['status' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
        $this->withToken($sellerToken)->postJson("/api/seller/return-requests/{$id}", ['status' => 'refunded', 'refund_amount' => 100, 'outcome_reference' => 'TX-1'])->assertForbidden();
        $this->withToken($sellerToken)->postJson("/api/seller/return-requests/{$id}", ['status' => 'received'])->assertOk()->assertJsonPath('status', 'received');
        $this->withToken($admin->createToken('test', ['admin:orders'])->plainTextToken)->postJson("/api/admin/return-requests/{$id}", ['status' => 'refunded', 'refund_amount' => 100, 'outcome_reference' => 'TX-1'])->assertOk()->assertJsonPath('status', 'refunded');
    }

    public function test_completed_refund_creates_wallet_credit_and_withdrawal_is_reserved(): void
    {
        $customer = Customer::create(['name' => 'Wallet Buyer', 'email' => 'wallet@example.test', 'password' => 'password123']);
        $seller = Seller::create(['seller_name' => 'Wallet Seller', 'email' => 'wallet-seller@example.test', 'store_name' => 'Wallet Store', 'store_slug' => 'wallet-store', 'kyc_type' => 'nid', 'kyc_number' => '2', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Wallet Admin', 'email' => 'wallet-admin@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $order = Order::create(['order_number' => 'WALLET-1', 'customer_id' => $customer->id, 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 100, 'total' => 100, 'shipping_charge' => 0, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);
        $item = OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Bag', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'fulfillment_status' => 'delivered']);
        $customerToken = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $returnId = $this->withToken($customerToken)->postJson('/api/customers/return-requests', ['order_item_id' => $item->id, 'type' => 'refund', 'quantity' => 1, 'reason' => 'Damaged'])->json('id');
        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($sellerToken)->postJson("/api/seller/return-requests/{$returnId}", ['status' => 'approved'])->assertOk();
        $this->withToken($sellerToken)->postJson("/api/seller/return-requests/{$returnId}", ['status' => 'received'])->assertOk();
        $this->withToken($admin->createToken('orders', ['admin:orders'])->plainTextToken)->postJson("/api/admin/return-requests/{$returnId}", ['status' => 'refunded', 'refund_amount' => 100, 'outcome_reference' => 'REF-1'])->assertOk();
        $this->withToken($customerToken)->getJson('/api/customers/withdrawals')->assertOk()->assertJsonPath('balance', 100);
        $withdrawal = $this->withToken($customerToken)->postJson('/api/customers/withdrawals', ['amount' => 100, 'method' => 'bKash', 'account_details' => '01700000000'])->assertCreated()->json('id');
        $this->withToken($customerToken)->postJson('/api/customers/withdrawals', ['amount' => 1, 'method' => 'bKash', 'account_details' => '01700000000'])->assertUnprocessable();
        $this->withToken($admin->createToken('basic', ['admin:basic'])->plainTextToken)->postJson("/api/admin/withdrawals/{$withdrawal}", ['status' => 'rejected'])->assertOk();
        $this->withToken($customerToken)->getJson('/api/customers/withdrawals')->assertOk()->assertJsonPath('balance', 100);
    }
}
