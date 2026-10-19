<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\FraudGuardBlock;
use App\Models\FraudGuardSetting;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutOrderRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_preview_does_not_require_turnstile_and_uses_district_rate(): void
    {
        config(['services.turnstile.secret_key' => 'configured']);
        [, $product, $payment, $inside, $outside] = $this->fixtures();

        $this->postJson('/api/guest/orders/preview', $this->payload($product, $payment, $outside) + [
            'shipping_name' => 'Guest', 'shipping_phone' => '01712345678',
            'shipping_address' => 'Dhaka', 'shipping_district' => 'Dhaka',
        ])->assertOk()
            ->assertJsonPath('shipping_method.code', 'inside_dhaka')
            ->assertJsonPath('shipping_charge', 80);
    }

    public function test_details_only_order_update_keeps_address_parts(): void
    {
        [$customer, $product, $payment, $inside] = $this->fixtures();
        $order = Order::create([
            'order_number' => 'ADDRESS-PARTS', 'customer_id' => $customer->id,
            'payment_method_id' => $payment->id, 'shipping_method_id' => $inside->id,
            'status' => 'pending', 'payment_status' => 'unpaid', 'subtotal' => 100,
            'shipping_charge' => 80, 'discount_total' => 0, 'total' => 180,
            'shipping_name' => 'Buyer', 'shipping_phone' => '01712345678', 'shipping_address' => 'Address',
        ]);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'address-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:orders'])->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/orders/{$order->id}", [
            'shipping_area' => 'Dhanmondi', 'shipping_district' => 'Dhaka',
        ])->assertOk()
            ->assertJsonPath('order.shipping_area', 'Dhanmondi')
            ->assertJsonPath('order.shipping_district', 'Dhaka');
    }

    public function test_staff_can_create_an_order_for_a_blocked_phone(): void
    {
        [$customer, $product, $payment, $inside] = $this->fixtures();
        FraudGuardSetting::create(['enabled' => true, 'phone_blacklist' => true, 'ip_block' => true, 'device_block' => true, 'fake_number_detection' => false]);
        FraudGuardBlock::create(['kind' => 'phone', 'value' => '01712345678', 'reason' => 'Test block']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'blocked-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:orders'])->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/orders', $this->payload($product, $payment, $inside) + [
            'shipping_name' => 'Blocked Buyer', 'shipping_phone' => '01712345678', 'shipping_address' => 'Dhaka',
        ])->assertCreated();
    }

    public function test_preview_lists_every_automatic_discount_and_persists_each_redemption(): void
    {
        [$customer, $product, $payment, $inside] = $this->fixtures();
        Coupon::create(['code' => 'AUTO-TEN', 'name' => 'Ten off', 'is_automatic' => true, 'is_active' => true, 'discount_type' => 'fixed', 'discount_value' => 10]);
        Coupon::create(['code' => 'AUTO-TWENTY', 'name' => 'Twenty off', 'is_automatic' => true, 'is_active' => true, 'discount_type' => 'fixed', 'discount_value' => 20]);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $payload = $this->payload($product, $payment, $inside);

        $this->withToken($token)->postJson('/api/customers/orders/preview', $payload)
            ->assertOk()
            ->assertJsonCount(2, 'applied_discounts')
            ->assertJsonPath('discount_total', 30);
        $this->withToken($token)->postJson('/api/customers/orders', $payload + ['shipping_name' => 'Buyer', 'shipping_phone' => '01712345678', 'shipping_address' => 'Dhaka'])->assertCreated()
            ->assertJsonPath('order.discount_total', '30.00');
        $this->assertDatabaseCount('coupon_redemptions', 2);
    }

    private function fixtures(): array
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'password123', 'email_verified_at' => now()]);
        $category = ProductCategory::create(['name' => 'Category', 'slug' => 'category']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Product', 'slug' => 'product', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 100]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'LOT-1', 'buying_price' => 50, 'selling_price' => 100, 'quantity' => 10, 'quantity_remaining' => 10]);
        $payment = PaymentMethod::create(['name' => 'COD', 'code' => 'cod', 'is_active' => true]);
        $inside = ShippingMethod::create(['name' => 'Inside Dhaka', 'code' => 'inside_dhaka', 'charge' => 80, 'currency' => 'BDT', 'is_active' => true]);
        $outside = ShippingMethod::create(['name' => 'Outside Dhaka', 'code' => 'outside_dhaka', 'charge' => 120, 'currency' => 'BDT', 'is_active' => true]);
        return [$customer, $product, $payment, $inside, $outside];
    }

    private function payload(Product $product, PaymentMethod $payment, ShippingMethod $shipping): array
    {
        return [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
        ];
    }
}
