<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Models\ProductLot;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\ShippingMethod;
use App\Services\ProductBundleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_simple_bundle_is_saved_and_resolved(): void
    {
        $product = $this->simpleProduct();
        $service = app(ProductBundleService::class);
        $service->replace($product, [[
            'label' => '10 pcs combo', 'mode' => 'fixed', 'size' => 10, 'price' => 999,
            'items' => [['attributes' => null, 'quantity' => 10]],
        ]]);

        $bundle = $product->bundles()->with('items')->firstOrFail();
        $resolved = $service->resolve($product, ['bundle_id' => $bundle->id, 'quantity' => 1]);
        $this->assertSame('10 pcs combo', $resolved['bundle']->label);
        $this->assertNull($resolved['components'][0]['variation']);
        $this->assertSame(10, $resolved['components'][0]['quantity']);
    }

    public function test_pick_bundle_requires_each_selected_variation_once(): void
    {
        $category = ProductCategory::create(['name' => 'Bundle category', 'slug' => 'bundle-category']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Bundle product', 'slug' => 'bundle-product', 'product_type' => 'variable', 'status' => 'active', 'option_groups' => [[
            'key' => 'colour', 'label' => 'Colour', 'display_type' => 'button', 'values' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']],
        ]]]);
        $red = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'red'], 'is_active' => true]);
        $blue = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'blue'], 'is_active' => true]);
        $service = app(ProductBundleService::class);
        $service->replace($product, [['label' => 'Two designs', 'mode' => 'pick', 'size' => 2, 'price' => 100]]);

        $bundle = $product->bundles()->firstOrFail();
        $resolved = $service->resolve($product, ['bundle_id' => $bundle->id, 'bundle_items' => [
            ['variation_id' => $red->id, 'quantity' => 1], ['variation_id' => $blue->id, 'quantity' => 1],
        ]]);
        $this->assertSame([$red->id, $blue->id], array_map(fn ($piece) => $piece['variation']->id, $resolved['components']));
    }

    public function test_public_product_includes_variant_photo_and_bundles(): void
    {
        $category = ProductCategory::create(['name' => 'Public combos', 'slug' => 'public-combos']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Public bundle', 'slug' => 'public-bundle', 'product_type' => 'variable', 'status' => 'active', 'option_groups' => [[
            'key' => 'colour', 'label' => 'Colour', 'display_type' => 'button', 'values' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']],
        ]]]);
        $red = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'red'], 'image_url' => 'https://media.example.test/red.jpg', 'is_active' => true]);
        ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'blue'], 'is_active' => true]);
        app(ProductBundleService::class)->replace($product, [['label' => 'Two colours', 'mode' => 'pick', 'size' => 2, 'price' => 100]]);

        $this->getJson('/api/store/products/public-bundle')->assertOk()
            ->assertJsonPath('variations.0.image_url', $red->image_url)
            ->assertJsonPath('bundles.0.label', 'Two colours');
    }

    public function test_checkout_prices_a_combo_as_one_line_and_consumes_each_piece(): void
    {
        $category = ProductCategory::create(['name' => 'Checkout combos', 'slug' => 'checkout-combos']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Combo coat', 'slug' => 'combo-coat', 'product_type' => 'variable', 'status' => 'active', 'default_selling_price' => 100, 'option_groups' => [[
            'key' => 'colour', 'label' => 'Colour', 'display_type' => 'button', 'values' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']],
        ]]]);
        $red = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'red'], 'default_selling_price' => 100, 'is_active' => true]);
        $blue = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'blue'], 'default_selling_price' => 100, 'is_active' => true]);
        foreach ([$red, $blue] as $variation) ProductLot::create(['variation_id' => $variation->id, 'lot_number' => "BUNDLE-{$variation->id}", 'buying_price' => 30, 'selling_price' => 100, 'quantity' => 5, 'quantity_remaining' => 5]);
        app(ProductBundleService::class)->replace($product, [['label' => 'Two-piece set', 'mode' => 'pick', 'size' => 2, 'price' => 150]]);
        $bundle = $product->bundles()->firstOrFail();
        $customer = Customer::create(['name' => 'Bundle customer', 'email' => 'bundle-customer@example.test', 'phone' => '01700000000', 'password' => 'password123']);
        $payment = PaymentMethod::create(['name' => 'Cash on Delivery', 'code' => 'cod', 'is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Outside Dhaka', 'code' => 'outside_dhaka', 'charge' => 20, 'currency' => 'BDT', 'is_active' => true]);

        $order = $this->withToken($customer->createToken('test', ['customer:basic'])->plainTextToken)->postJson('/api/customers/orders', [
            'items' => [['product_id' => $product->id, 'bundle_id' => $bundle->id, 'bundle_items' => [['variation_id' => $red->id, 'quantity' => 1], ['variation_id' => $blue->id, 'quantity' => 1]], 'quantity' => 1]],
            'payment_method_id' => $payment->id, 'shipping_method_id' => $shipping->id, 'shipping_name' => 'Bundle customer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka',
        ])->assertCreated()->json('order');

        $this->assertSame('150.00', $order['items'][0]['line_subtotal']);
        $this->assertSame('Two-piece set', $order['items'][0]['bundle_label']);
        $this->assertDatabaseHas('product_lots', ['id' => ProductLot::where('variation_id', $red->id)->value('id'), 'quantity_remaining' => 4]);
        $this->assertDatabaseHas('product_lots', ['id' => ProductLot::where('variation_id', $blue->id)->value('id'), 'quantity_remaining' => 4]);
    }

    private function simpleProduct(): Product
    {
        $category = ProductCategory::create(['name' => 'Simple category', 'slug' => 'simple-category']);
        return Product::create(['category_id' => $category->id, 'name' => 'Simple product', 'slug' => 'simple-product', 'product_type' => 'simple', 'status' => 'active']);
    }
}
