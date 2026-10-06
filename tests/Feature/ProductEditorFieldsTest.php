<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Admin;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Services\ProductHtmlSanitizer;
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

    public function test_editor_reports_compare_at_price_from_regular_and_inventory_prices(): void
    {
        $admin = Admin::create(['name' => 'Owner', 'email' => 'pricing-owner@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $category = ProductCategory::create(['name' => 'Coats', 'slug' => 'pricing-coats']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Pink coat', 'slug' => 'pricing-pink-coat', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 500]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'SALE-1', 'buying_price' => 200, 'selling_price' => 360, 'quantity' => 5, 'quantity_remaining' => 5]);

        $this->withToken($token)->getJson("/api/admin/products/{$product->id}")
            ->assertOk()->assertJsonPath('current_selling_price', '360.00')->assertJsonPath('compare_at_price', '500.00');
    }
}
