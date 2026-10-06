<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Admin;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Services\ProductHtmlSanitizer;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEditorFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_editor_fields_are_cast_and_persisted(): void
    {
        $product = new Product();
        $product->fill([
            'specification_tables' => [['title' => 'Material', 'rows' => [['label' => 'Fabric', 'value' => 'Cotton']]]],
            'videos' => ['https://example.com/demo.mp4'],
            'weight_kg' => 0.75,
        ]);

        $this->assertSame('Cotton', $product->specification_tables[0]['rows'][0]['value']);
        $this->assertSame(['https://example.com/demo.mp4'], $product->videos);
        $this->assertEquals(0.75, $product->weight_kg);
        $this->assertTrue(\Schema::hasColumns('products', ['specification_tables', 'videos', 'weight_kg']));
    }

    public function test_product_description_removes_unsafe_markup(): void
    {
        $html = app(ProductHtmlSanitizer::class)->clean('<p onclick="evil()">Hello<script>alert(1)</script><a href="javascript:alert(1)">bad</a><a href="https://example.com">good</a></p>');

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_editor_reports_independent_product_prices_and_inventory_cost(): void
    {
        $admin = Admin::create(['name' => 'Owner', 'email' => 'pricing-owner@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $category = ProductCategory::create(['name' => 'Coats', 'slug' => 'pricing-coats']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Pink coat', 'slug' => 'pricing-pink-coat', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 360, 'compare_at_price' => 500]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'SALE-1', 'buying_price' => 200, 'selling_price' => 360, 'quantity' => 5, 'quantity_remaining' => 5]);

        $this->withToken($token)->getJson("/api/admin/products/{$product->id}")
            ->assertOk()->assertJsonPath('default_selling_price', 360)->assertJsonPath('compare_at_price', '500.00')->assertJsonPath('current_buying_price', 200);
    }

    public function test_storefront_and_checkout_use_product_price_instead_of_lot_selling_snapshot(): void
    {
        $category = ProductCategory::create(['name' => 'Jackets', 'slug' => 'pricing-jackets']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Jacket', 'slug' => 'pricing-jacket', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 420, 'compare_at_price' => 600]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'OLD-PRICE', 'buying_price' => 200, 'selling_price' => 360, 'quantity' => 5, 'quantity_remaining' => 5]);

        $this->getJson('/api/store/products/pricing-jacket')->assertOk()
            ->assertJsonPath('current_selling_price', '420')->assertJsonPath('compare_at_price', '600.00');
        $quote = app(InventoryService::class)->previewProduct($product, 2);
        $this->assertSame(840.0, $quote['subtotal']);
        $this->assertSame(400.0, $quote['cost']);
        $sale = app(InventoryService::class)->consumeProduct($product, 2, null);
        $this->assertSame(840.0, $sale['totals']['revenue']);
        $this->assertSame(400.0, $sale['totals']['cost']);
        $this->assertSame('420.00', $sale['allocations'][0]['unit_selling_price']);
    }

    public function test_legacy_price_backfill_prefers_the_available_lot(): void
    {
        $category = ProductCategory::create(['name' => 'Shirts', 'slug' => 'pricing-shirts']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Shirt', 'slug' => 'pricing-shirt', 'product_type' => 'simple', 'status' => 'active']);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'OLD', 'buying_price' => 50, 'selling_price' => 100, 'quantity' => 2, 'quantity_remaining' => 0]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'CURRENT', 'buying_price' => 60, 'selling_price' => 130, 'quantity' => 2, 'quantity_remaining' => 2]);

        $migration = require database_path('migrations/2026_10_06_000011_backfill_catalogue_selling_prices.php');
        $migration->up();

        $this->assertEquals(130, $product->fresh()->default_selling_price);
    }
}
