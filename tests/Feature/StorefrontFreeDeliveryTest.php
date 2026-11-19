<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use App\Models\ProductLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontFreeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_products_expose_eligible_automatic_free_delivery(): void
    {
        $men = ProductCategory::create(['name' => 'Free delivery men', 'slug' => 'free-delivery-men']);
        $hoodies = ProductCategory::create(['name' => 'Hoodies', 'slug' => 'free-delivery-men-hoodies', 'parent_id' => $men->id]);
        $product = Product::create(['category_id' => $hoodies->id, 'name' => 'Free hoodie', 'slug' => 'free-hoodie', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 500]);
        $other = Product::create(['category_id' => $hoodies->id, 'name' => 'Low hoodie', 'slug' => 'low-hoodie', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 99]);
        foreach ([$product, $other] as $item) {
            ProductLot::create(['product_id' => $item->id, 'lot_number' => "FREE-{$item->id}", 'buying_price' => 10, 'selling_price' => $item->default_selling_price, 'quantity' => 1, 'quantity_remaining' => 1]);
        }
        $collection = ProductCollection::create(['name' => 'Winter']);
        $collection->products()->attach($product->id, ['sort_order' => 0]);

        Coupon::create(['code' => 'AUTO-SHIPPING', 'is_automatic' => true, 'discount_kind' => 'shipping', 'discount_type' => 'fixed', 'discount_value' => 0, 'eligible_collection_ids' => [$collection->id], 'minimum_order_amount' => 100, 'is_active' => true]);
        Coupon::create(['code' => 'EXPIRED-SHIPPING', 'is_automatic' => true, 'discount_kind' => 'shipping', 'discount_type' => 'fixed', 'discount_value' => 0, 'eligible_category_ids' => [$men->id], 'is_active' => true, 'expires_at' => now()->subMinute()]);

        $products = $this->getJson('/api/store/products?per_page=100')->assertOk()->json('data');
        $flags = collect($products)->mapWithKeys(fn (array $item) => [$item['slug'] => $item['free_delivery']]);
        $this->assertTrue($flags['free-hoodie']);
        $this->assertFalse($flags['low-hoodie']);

        $this->getJson('/api/store/products/free-hoodie')->assertOk()->assertJsonPath('free_delivery', true);
    }
}
