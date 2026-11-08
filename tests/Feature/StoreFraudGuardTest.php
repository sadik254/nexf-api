<?php

namespace Tests\Feature;

use App\Models\FraudGuardBlock;
use App\Models\FraudGuardSetting;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\Seller;
use App\Models\SellerStaff;
use App\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreFraudGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_guard_blocks_only_its_own_checkout_lines(): void
    {
        [$first, $second, $payment, $shipping] = $this->storeFixtures();
        FraudGuardSetting::create(['seller_id' => $first->seller_id, 'enabled' => true, 'ip_block' => false, 'device_block' => false, 'phone_blacklist' => true, 'fake_number_detection' => false]);
        FraudGuardBlock::create(['seller_id' => $first->seller_id, 'kind' => 'phone', 'value' => '01712345678']);

        $this->postJson('/api/guest/orders/preview', [
            'items' => [['product_id' => $first->id, 'quantity' => 1], ['product_id' => $second->id, 'quantity' => 1]],
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'shipping_name' => 'Buyer', 'shipping_phone' => '01712345678', 'shipping_address' => 'Dhaka',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0')
            ->assertJsonMissingValidationErrors('items.1');
    }

    public function test_seller_guard_routes_are_scoped_and_staff_cannot_delete_blocks(): void
    {
        [$first] = $this->storeFixtures();
        $seller = $first->seller;
        $sellerToken = $seller->createToken('seller')->plainTextToken;

        $this->withToken($sellerToken)->getJson('/api/seller/fraud-guard')
            ->assertOk()->assertJsonPath('settings.enabled', false);
        $this->withToken($sellerToken)->postJson('/api/seller/fraud-guard/settings', [
            'enabled' => true, 'ip_block' => false, 'device_block' => false,
            'phone_blacklist' => true, 'fake_number_detection' => false,
        ])->assertOk()->assertJsonPath('settings.seller_id', $seller->id);
        $blockId = $this->withToken($sellerToken)->postJson('/api/seller/fraud-guard/blocks', [
            'kind' => 'phone', 'value' => '01712345678', 'reason' => 'Repeat refusal',
        ])->assertOk()->json('blocks.0.id');

        $staff = SellerStaff::create(['seller_id' => $seller->id, 'name' => 'Store staff', 'email' => 'fraud-staff@example.test', 'password' => 'password123', 'status' => 'active']);
        $staffToken = $staff->createToken('seller')->plainTextToken;
        $this->withToken($staffToken)->postJson("/api/seller/fraud-guard/blocks/{$blockId}/delete")
            ->assertForbidden();
    }

    /** @return array{Product, Product, PaymentMethod, ShippingMethod} */
    private function storeFixtures(): array
    {
        $category = ProductCategory::create(['name' => 'Fraud category', 'slug' => 'fraud-category']);
        $sellerOne = Seller::create(['seller_name' => 'One', 'store_name' => 'First Store', 'store_slug' => 'first-store', 'email' => 'first-store@example.test', 'phone' => '01700000001', 'password' => 'password123', 'status' => 'approved', 'is_active' => true]);
        $sellerTwo = Seller::create(['seller_name' => 'Two', 'store_name' => 'Second Store', 'store_slug' => 'second-store', 'email' => 'second-store@example.test', 'phone' => '01700000002', 'password' => 'password123', 'status' => 'approved', 'is_active' => true]);
        $first = Product::create(['seller_id' => $sellerOne->id, 'category_id' => $category->id, 'name' => 'First product', 'slug' => 'first-product', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 100]);
        $second = Product::create(['seller_id' => $sellerTwo->id, 'category_id' => $category->id, 'name' => 'Second product', 'slug' => 'second-product', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 100]);
        foreach ([$first, $second] as $product) ProductLot::create(['product_id' => $product->id, 'lot_number' => 'LOT-'.$product->id, 'buying_price' => 50, 'selling_price' => 100, 'quantity' => 10, 'quantity_remaining' => 10]);
        $payment = PaymentMethod::create(['name' => 'Cash on Delivery', 'code' => 'cod', 'is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Inside Dhaka', 'code' => 'inside_dhaka', 'charge' => 80, 'currency' => 'BDT', 'is_active' => true]);
        return [$first, $second, $payment, $shipping];
    }
}
