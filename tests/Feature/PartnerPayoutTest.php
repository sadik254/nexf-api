<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PartnerWithdrawal;
use App\Models\ReturnRequest;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_manages_a_payout_account_and_admin_settles_request(): void
    {
        $seller = Seller::create([
            'seller_name' => 'Payout Seller', 'email' => 'payout-seller@example.test', 'store_name' => 'Payout Store',
            'store_slug' => 'payout-store', 'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $sellerToken = $seller->createToken('test', ['seller'])->plainTextToken;
        $account = $this->withToken($sellerToken)->postJson('/api/seller/payout-accounts', [
            'provider' => 'bkash', 'account' => '01700000000',
        ])->assertCreated()->assertJsonPath('isSelected', true)->json('id');
        $this->withToken($sellerToken)->getJson('/api/seller/payouts')->assertOk()
            ->assertJsonPath('rules.min_amount', 500)->assertJsonPath('accounts.0.id', $account);
        $this->withToken($sellerToken)->postJson('/api/seller/payout-accounts', [
            'provider' => 'upay', 'account' => '01800000000',
        ])->assertCreated()->assertJsonPath('provider', 'upay');

        $withdrawal = PartnerWithdrawal::create(['seller_id' => $seller->id, 'partner_payout_account_id' => $account, 'amount' => 500, 'reference' => 'PAY-00001']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'payout-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;
        $this->withToken($adminToken)->postJson("/api/admin/partner-withdrawals/{$withdrawal->id}", [
            'status' => 'paid', 'transaction_id' => 'TXN-1',
        ])->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('payee.store_name', 'Payout Store');
    }

    public function test_only_balance_refunds_reduce_a_sellers_payout(): void
    {
        $seller = Seller::create(['seller_name' => 'Refund Seller', 'email' => 'refund-payout@example.test', 'store_name' => 'Refund Store', 'store_slug' => 'refund-store', 'status' => 'approved', 'is_active' => true, 'password' => 'password123', 'commission_rate' => 10]);
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'refund-buyer@example.test', 'password' => 'password123']);
        $order = Order::create(['order_number' => 'REFUND-PAYOUT', 'customer_id' => $customer->id, 'status' => 'delivered', 'subtotal' => 200, 'total' => 200, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);
        $balanceItem = OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Balance item', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'commission_rate' => 10, 'fulfillment_status' => 'delivered', 'delivered_at' => now()]);
        $originalItem = OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Original item', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'commission_rate' => 10, 'fulfillment_status' => 'delivered', 'delivered_at' => now()]);
        ReturnRequest::create(['order_id' => $order->id, 'order_item_id' => $balanceItem->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'reference' => 'RET-BALANCE', 'type' => 'refund', 'status' => 'refunded', 'quantity' => 1, 'reason' => 'Damaged', 'refund_to' => 'balance', 'refund_amount' => 100, 'completed_at' => now()]);
        ReturnRequest::create(['order_id' => $order->id, 'order_item_id' => $originalItem->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'reference' => 'RET-ORIGINAL', 'type' => 'refund', 'status' => 'refunded', 'quantity' => 1, 'reason' => 'Damaged', 'refund_to' => 'original', 'refund_amount' => 100, 'completed_at' => now()]);

        $earnings = $this->withToken($seller->createToken('test', ['seller:basic'])->plainTextToken)->getJson('/api/seller/payouts')->assertOk()->json('earnings');

        $this->assertTrue(collect($earnings)->contains('id', 'refund-' . ReturnRequest::where('reference', 'RET-BALANCE')->value('id')));
        $this->assertFalse(collect($earnings)->contains('id', 'refund-' . ReturnRequest::where('reference', 'RET-ORIGINAL')->value('id')));
    }
}
