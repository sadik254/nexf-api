<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Models\ProductLot;
use App\Models\Customer;
use App\Models\Coupon;
use App\Models\PaymentMethod;
use App\Models\ShippingMethod;
use App\Services\ProductBundleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
        $red = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'red'], 'default_selling_price' => 100, 'is_active' => true]);
        $blue = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'blue'], 'default_selling_price' => 100, 'is_active' => true]);
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
        $this->assertSame('2 pcs', $order['items'][0]['bundle_label']);
        $this->assertDatabaseHas('product_lots', ['id' => ProductLot::where('variation_id', $red->id)->value('id'), 'quantity_remaining' => 4]);
        $this->assertDatabaseHas('product_lots', ['id' => ProductLot::where('variation_id', $blue->id)->value('id'), 'quantity_remaining' => 4]);
    }

    public function test_coupon_validation_keeps_combo_data_and_parent_categories_apply_to_children(): void
    {
        $parent = ProductCategory::create(['name' => 'Women', 'slug' => 'coupon-women']);
        $category = ProductCategory::create(['name' => 'T-shirts', 'slug' => 'coupon-t-shirts', 'parent_id' => $parent->id]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Coupon combo', 'slug' => 'coupon-combo', 'product_type' => 'variable', 'status' => 'active', 'option_groups' => [[
            'key' => 'colour', 'label' => 'Colour', 'display_type' => 'button', 'values' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']],
        ]]]);
        $red = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'red'], 'default_selling_price' => 100, 'is_active' => true]);
        $blue = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => 'blue'], 'default_selling_price' => 100, 'is_active' => true]);
        foreach ([$red, $blue] as $variation) ProductLot::create(['variation_id' => $variation->id, 'lot_number' => "COUPON-{$variation->id}", 'buying_price' => 30, 'selling_price' => 100, 'quantity' => 5, 'quantity_remaining' => 5]);
        app(ProductBundleService::class)->replace($product, [['label' => 'Two-piece set', 'mode' => 'pick', 'size' => 2, 'price' => 150]]);
        $bundle = $product->bundles()->firstOrFail();
        Coupon::create(['code' => 'WOMEN20', 'discount_kind' => 'products', 'discount_type' => 'fixed', 'discount_value' => 20, 'eligible_category_ids' => [$parent->id], 'is_active' => true]);

        $this->postJson('/api/coupons/validate', ['code' => 'WOMEN20', 'items' => [[
            'product_id' => $product->id, 'bundle_id' => $bundle->id,
            'bundle_items' => [['variation_id' => $red->id, 'quantity' => 1], ['variation_id' => $blue->id, 'quantity' => 1]], 'quantity' => 1,
        ]]])->assertOk()->assertJsonPath('discount_amount', 20);
    }

    public function test_pick_combo_can_include_more_variations_than_its_base_size(): void
    {
        $category = ProductCategory::create(['name' => 'Oversized combo category', 'slug' => 'oversized-combo-category']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Oversized combo', 'slug' => 'oversized-combo', 'product_type' => 'variable', 'status' => 'active', 'option_groups' => [[
            'key' => 'colour', 'label' => 'Colour', 'display_type' => 'button', 'values' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue'], ['value' => 'green', 'label' => 'Green']],
        ]]]);
        $variations = collect(['red', 'blue', 'green'])->map(fn (string $colour) => ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => $colour], 'default_selling_price' => 100, 'is_active' => true]));
        foreach ($variations as $variation) ProductLot::create(['variation_id' => $variation->id, 'lot_number' => "MORE-{$variation->id}", 'buying_price' => 30, 'selling_price' => 100, 'quantity' => 5, 'quantity_remaining' => 5]);
        app(ProductBundleService::class)->replace($product, [['label' => '2 pcs deal', 'mode' => 'pick', 'size' => 2, 'price' => 150]]);
        $bundle = $product->bundles()->firstOrFail();
        $pieces = $variations->map(fn (ProductVariation $variation) => ['variation_id' => $variation->id, 'quantity' => 1])->all();

        $resolved = app(ProductBundleService::class)->resolve($product, ['bundle_id' => $bundle->id, 'bundle_items' => $pieces]);
        $this->assertSame('3 pcs', $resolved['label']);
        $this->assertSame(225.0, $resolved['unitPrice']);

        $customer = Customer::create(['name' => 'More picks buyer', 'email' => 'more-picks@example.test', 'phone' => '01700000001', 'password' => 'password123']);
        $payment = PaymentMethod::create(['name' => 'Cash on Delivery', 'code' => 'cod', 'is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Outside Dhaka', 'code' => 'outside_dhaka', 'charge' => 20, 'currency' => 'BDT', 'is_active' => true]);
        $order = $this->withToken($customer->createToken('test', ['customer:basic'])->plainTextToken)->postJson('/api/customers/orders', [
            'items' => [['product_id' => $product->id, 'bundle_id' => $bundle->id, 'bundle_items' => $pieces, 'quantity' => 1]],
            'payment_method_id' => $payment->id, 'shipping_method_id' => $shipping->id, 'shipping_name' => 'More picks buyer', 'shipping_phone' => '01700000001', 'shipping_address' => 'Dhaka',
        ])->assertCreated()->json('order');
        $this->assertSame('3 pcs', $order['items'][0]['bundle_label']);
        $this->assertSame('225.00', $order['items'][0]['line_subtotal']);
    }

    public function test_server_selects_the_largest_eligible_pick_tier_and_snapshots_it(): void
    {
        $category = ProductCategory::create(['name' => 'Tiered combos', 'slug' => 'tiered-combos']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Tiered combo', 'slug' => 'tiered-combo', 'product_type' => 'variable', 'status' => 'active', 'option_groups' => [[
            'key' => 'colour', 'label' => 'Colour', 'display_type' => 'button', 'values' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue'], ['value' => 'green', 'label' => 'Green']],
        ]]]);
        $variations = collect(['red', 'blue', 'green'])->map(fn (string $colour) => ProductVariation::create(['product_id' => $product->id, 'attributes' => ['colour' => $colour], 'default_selling_price' => 100, 'is_active' => true]));
        foreach ($variations as $variation) ProductLot::create(['variation_id' => $variation->id, 'lot_number' => "TIER-{$variation->id}", 'buying_price' => 30, 'selling_price' => 100, 'quantity' => 5, 'quantity_remaining' => 5]);

        $service = app(ProductBundleService::class);
        $service->replace($product, [
            ['label' => '2 pcs deal', 'mode' => 'pick', 'size' => 2, 'price' => 150],
            ['label' => '3 pcs deal', 'mode' => 'pick', 'size' => 3, 'price' => 270],
        ]);
        $smallTier = $product->bundles()->where('size', 2)->firstOrFail();
        $largeTier = $product->bundles()->where('size', 3)->firstOrFail();
        $pieces = $variations->map(fn (ProductVariation $variation) => ['variation_id' => $variation->id, 'quantity' => 1])->all();

        // A forged request names the cheaper tier. The API selects the three-piece
        // tier from the selection itself, rather than trusting bundle_id for price.
        $resolved = $service->resolve($product, ['bundle_id' => $smallTier->id, 'bundle_items' => $pieces]);
        $this->assertSame($largeTier->id, $resolved['bundle']->id);
        $this->assertSame(270.0, $resolved['unitPrice']);

        $customer = Customer::create(['name' => 'Tier buyer', 'email' => 'tier-buyer@example.test', 'phone' => '01700000002', 'password' => 'password123']);
        $payment = PaymentMethod::create(['name' => 'Cash on Delivery', 'code' => 'cod', 'is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Outside Dhaka', 'code' => 'outside_dhaka', 'charge' => 20, 'currency' => 'BDT', 'is_active' => true]);
        $order = $this->withToken($customer->createToken('test', ['customer:basic'])->plainTextToken)->postJson('/api/customers/orders', [
            'items' => [['product_id' => $product->id, 'bundle_id' => $smallTier->id, 'bundle_items' => $pieces, 'quantity' => 1]],
            'payment_method_id' => $payment->id, 'shipping_method_id' => $shipping->id, 'shipping_name' => 'Tier buyer', 'shipping_phone' => '01700000002', 'shipping_address' => 'Dhaka',
        ])->assertCreated()->json('order');

        $this->assertSame('3 pcs', $order['items'][0]['bundle_label']);
        $this->assertSame('270.00', $order['items'][0]['line_subtotal']);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order['id'],
            'bundle_source_id' => $largeTier->id,
            'bundle_source_label' => '3 pcs deal',
            'bundle_source_size' => 3,
            'bundle_source_price' => 270,
        ]);
    }

    public function test_pick_combo_price_rounds_half_a_paisa_up_with_integer_math(): void
    {
        $product = $this->simpleProduct();
        $bundle = ProductBundle::create([
            'product_id' => $product->id,
            'label' => 'Tiny price test',
            'mode' => 'pick',
            'size' => 2,
            'price' => '0.03',
        ]);

        // ৳0.03 ÷ 2 is 1.5 paisa, which must be rounded once to 2 paisa.
        $this->assertSame(0.02, ProductBundleService::pickPrice($bundle, 1));
        $this->assertSame(0.03, ProductBundleService::pickPrice($bundle, 2));
    }

    public function test_unavailable_pick_design_is_named_on_its_cart_line(): void
    {
        $category = ProductCategory::create(['name' => 'Unavailable designs', 'slug' => 'unavailable-designs']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Bird product', 'slug' => 'bird-product', 'product_type' => 'variable', 'status' => 'active', 'option_groups' => [[
            'key' => 'design', 'label' => 'Design', 'display_type' => 'button', 'values' => [['value' => 'birds', 'label' => 'Birds'], ['value' => 'flowers', 'label' => 'Flowers']],
        ]]]);
        $birds = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['design' => 'birds'], 'is_active' => false]);
        $flowers = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['design' => 'flowers'], 'is_active' => true]);
        $bundle = ProductBundle::create(['product_id' => $product->id, 'label' => 'Two designs', 'mode' => 'pick', 'size' => 2, 'price' => 100]);

        try {
            app(ProductBundleService::class)->resolve($product, [
                'bundle_id' => $bundle->id,
                'bundle_items' => [['variation_id' => $birds->id, 'quantity' => 1], ['variation_id' => $flowers->id, 'quantity' => 1]],
            ], false, 'items.1');
            $this->fail('Expected an unavailable design validation error.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Birds is no longer available. Remove this combo and pick again.'],
                $exception->errors()['items.1.bundle_items'] ?? [],
            );
        }
    }

    private function simpleProduct(): Product
    {
        $category = ProductCategory::create(['name' => 'Simple category', 'slug' => 'simple-category']);
        return Product::create(['category_id' => $category->id, 'name' => 'Simple product', 'slug' => 'simple-product', 'product_type' => 'simple', 'status' => 'active']);
    }
}
