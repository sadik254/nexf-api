<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\ShippingMethod;
use App\Services\WalletBalanceService;
use App\Services\CheckoutService;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletBucketTest extends TestCase
{
    use RefreshDatabase;

    public function test_rewards_are_visible_but_cannot_be_withdrawn(): void
    {
        $customer = Customer::create(['name' => 'Reward customer', 'email' => 'reward-bucket@example.test', 'password' => 'password123']);
        CustomerWalletTransaction::create(['customer_id' => $customer->id, 'type' => 'credit', 'kind' => 'cashback', 'bucket' => 'reward', 'amount' => 80, 'description' => 'Cashback']);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;

        $this->withToken($token)->getJson('/api/customers/wallet')
            ->assertOk()->assertJsonPath('balance', 80)->assertJsonPath('withdrawable_balance', 0)->assertJsonPath('transactions.0.bucket', 'reward');
        $this->withToken($token)->postJson('/api/customers/withdrawals', ['amount' => 1, 'method' => 'bkash', 'account_details' => '01700000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount')->assertJsonPath('errors.amount.0', 'Rewards can only be used at checkout.');
    }

    public function test_cancelling_a_wallet_order_restores_each_original_bucket(): void
    {
        $customer = Customer::create(['name' => 'Split customer', 'email' => 'split-bucket@example.test', 'password' => 'password123']);
        $order = Order::create(['order_number' => 'SPLIT-1', 'customer_id' => $customer->id, 'status' => 'pending', 'payment_method_code' => 'wallet', 'subtotal' => 100, 'total' => 100, 'shipping_charge' => 0, 'wallet_amount' => 100, 'wallet_reward_amount' => 60, 'wallet_withdrawable_amount' => 40, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);

        app(WalletLedgerService::class)->cancellationCredit($order);
        $buckets = app(WalletBalanceService::class)->buckets($customer->id);

        $this->assertSame(60.0, $buckets['reward']);
        $this->assertSame(40.0, $buckets['withdrawable']);
        $this->assertSame(2, CustomerWalletTransaction::count());
    }

    public function test_checkout_uses_rewards_before_withdrawable_balance(): void
    {
        $customer = Customer::create(['name' => 'Checkout customer', 'email' => 'checkout-bucket@example.test', 'password' => 'password123']);
        CustomerWalletTransaction::create(['customer_id' => $customer->id, 'type' => 'credit', 'kind' => 'cashback', 'bucket' => 'reward', 'amount' => 60, 'description' => 'Cashback']);
        CustomerWalletTransaction::create(['customer_id' => $customer->id, 'type' => 'credit', 'kind' => 'refund', 'bucket' => 'withdrawable', 'amount' => 80, 'description' => 'Refund']);
        $category = ProductCategory::create(['name' => 'Bucket category', 'slug' => 'bucket-category']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Bucket product', 'slug' => 'bucket-product', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 100]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'BUCKET-LOT', 'buying_price' => 50, 'selling_price' => 100, 'quantity' => 1, 'quantity_remaining' => 1]);
        $payment = PaymentMethod::create(['name' => 'Cash on Delivery', 'code' => 'cod', 'is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Standard', 'code' => 'standard', 'charge' => 0, 'currency' => 'BDT', 'is_active' => true]);

        $preview = app(CheckoutService::class)->preview($customer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'use_wallet' => true,
            'shipping_phone' => '01700000000',
            'shipping_address' => 'Dhaka',
        ]);

        $this->assertSame(100.0, $preview['wallet_amount']);
        $this->assertSame(60.0, $preview['wallet_reward_amount']);
        $this->assertSame(40.0, $preview['wallet_withdrawable_amount']);
    }
}
